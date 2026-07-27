<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Service;

use OCA\PenpotSync\AppInfo\Application;
use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Settings\InstanceSettings;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * The one place this app talks to Penpot.
 *
 * Both sibling apps (`nextcloud-n8n`, `nextcloud-grafana`) have a client like
 * this, and theirs are thinner — because both talk to a REST API with one
 * credential and one obvious shape. Penpot has none of those things, and the
 * differences are the reason this class was built FIRST, before the admin
 * surface (saga Ch2, Course 1):
 *
 * ## 1. It is an RPC bus, not REST
 *
 * Every call is `POST /api/rpc/command/<name>` with a JSON body. There are no
 * paths to build, no verbs to choose, and no status-code semantics to lean on.
 *
 * ## 2. There is no inferable parameter convention (saga §6.54)
 *
 * Four commands, four conventions — confirmed live, one at a time, by hitting
 * them:
 *
 *     import-binfile   →  project-id     (kebab)
 *     export-binfile   →  fileId         (camel)
 *     create-project   →  team-id        (kebab)
 *     rename-file      →  id             (bare!)
 *
 * That is not a convention with exceptions; it is four data points and no rule.
 * So `PARAMS` below is an explicit table, and callers never construct param
 * names themselves. Getting one wrong is not a soft failure — Penpot returns
 * HTTP 400 `:params-validation` with a `missing-key` explain body, which is at
 * least loud. The trap is that it is only loud once you actually call it.
 *
 * ## 3. Responses are Transit, not JSON — and asking nicely breaks that
 *
 * See {@see Transit}. Decoding is delegated there; this class never touches the
 * wire format itself.
 *
 * **Penpot content-negotiates**, so the request headers are part of the calling
 * contract just as much as the param names are: send `Accept: application/json`
 * and it answers plain camelCase JSON instead of Transit, which breaks every key
 * lookup here *and* the decoder's shape detection. See the header block in
 * {@see call()} — that comment is load-bearing, not decoration.
 *
 * ## 4. HTTP 200 will not mean success, once the binfile commands land
 *
 * `export-binfile`/`import-binfile` stream Server-Sent Events, and an ERROR
 * arrives as an event *inside* a 200 response (saga §5.1/§6.20). Neither is
 * implemented here yet — they arrive with the course that first needs an
 * export, together with an event reader — but the trap is named now because a
 * later contributor reading `decodeResponse()` could reasonably assume a 2xx
 * settles the question. For the ordinary RPC commands in the table below, it
 * does.
 *
 * ## 5. Success is not proof of success (saga §6.49)
 *
 * `restore-deleted-team-files` reported `end` while `deleted_at` was still set;
 * a second call cleared it. So any write that matters is confirmed by
 * re-reading, never by the response alone. That discipline lives at the call
 * sites, but it is stated here because this is the class that would otherwise
 * seem to promise it.
 *
 * ## What is deliberately NOT here
 *
 * No encoder. Every request we send is plain JSON with plain string values,
 * which Penpot accepts — verified live across every command in the table below.
 * A Transit *writer* would be speculative work for a problem we do not have.
 */
final class PenpotClient {
	/** AppConfig key holding the service-account token, stored encrypted. */
	public const KEY_TOKEN = 'penpot_token';

	/** Every RPC command lives under this prefix. */
	private const RPC_PATH = '/api/rpc/command/';

	/**
	 * THE PARAM TABLE (saga §6.54, open question #21).
	 *
	 * Maps a logical argument name to the exact wire param each command expects.
	 * This exists because there is no rule to infer — see the class docblock.
	 *
	 * Rows are only added once the command has been called live and the shape
	 * confirmed. An unconfirmed row would be a guess wearing a table's clothing.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PARAMS = [
		// ── confirmed live, Chapter 1 ──
		'get-teams' => [],
		'get-all-projects' => [],
		'get-projects' => ['team' => 'team-id'],
		'get-project-files' => ['project' => 'project-id'],
		'rename-file' => ['file' => 'id', 'name' => 'name'],
		'create-project' => ['team' => 'team-id', 'name' => 'name'],
		'rename-project' => ['project' => 'id', 'name' => 'name'],
		'get-team-deleted-files' => ['team' => 'team-id'],
	];

	/**
	 * Seconds to wait on an ordinary RPC call.
	 *
	 * NOTE the SSE commands (`export-binfile`, `import-binfile`) are deliberately
	 * absent from this class entirely. They stream events, need a much longer
	 * budget, and carry the HTTP-200-with-an-error-inside trap (saga §5.1/§6.20);
	 * they land with the course that first needs an export, along with their own
	 * event reader. Adding a half-built SSE path now would be untested surface.
	 */
	private const TIMEOUT = 30;

	public function __construct(
		private readonly IAppConfig $config,
		private readonly ICrypto $crypto,
		private readonly IClientService $clientService,
		private readonly Transit $transit,
		private readonly LoggerInterface $logger,
	) {
	}

	// ── the read surface ────────────────────────────────────────────────────

	/**
	 * The teams the configured token can see.
	 *
	 * ALWAYS MEMBERSHIP-SCOPED (saga §6.12): Penpot has no admin scope, so this
	 * returns the teams this *account* belongs to and nothing more. That is why
	 * a team must invite the service account as `viewer` before it can be mapped
	 * (saga §6.18) — and why "Test connection" reports which teams were visible
	 * rather than just "OK".
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	public function getTeams(): array {
		return $this->records($this->call('get-teams'));
	}

	/**
	 * Every project the token can see, across all its teams.
	 *
	 * USE THIS, NOT `get-projects` (saga §6.42, re-confirmed live §6.54).
	 * `get-projects` never filters `deleted_at`, so it returns soft-deleted
	 * projects indistinguishably from live ones — an upstream bug, still present.
	 * `get-all-projects` filters correctly.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	public function getAllProjects(): array {
		return $this->records($this->call('get-all-projects'));
	}

	/**
	 * The files in one project, with `revn` and `modified-at` for every file.
	 *
	 * THIS IS WHAT MAKES THE PULL CHEAP (saga §5.5): the drift check needs no
	 * per-file call, so an unchanged team costs 1 + P requests and zero exports.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	public function getProjectFiles(string $projectId): array {
		return $this->records($this->call('get-project-files', ['project' => $projectId]));
	}

	// ── the write surface ───────────────────────────────────────────────────

	/**
	 * Rename a design file. Ratified in saga §6.54.
	 *
	 * The `.penpot` extension is a Nextcloud-side affordance (saga §6.4) —
	 * Penpot's own name never carries it, so callers pass the bare name.
	 *
	 * `$actorToken`, when given, attributes the rename to that user in Penpot's
	 * history (saga §6.18); null attributes it to the service account.
	 *
	 * @return array<string, mixed> The updated `{id, name, created-at, modified-at}`.
	 *
	 * @throws PenpotApiException
	 */
	public function renameFile(string $fileId, string $name, ?string $actorToken = null): array {
		$this->assertName($name);

		return $this->record($this->call('rename-file', ['file' => $fileId, 'name' => $name], $actorToken));
	}

	/**
	 * Rename a project. Its own flow, not a variant of file rename (saga §6.39).
	 *
	 * Penpot answers 204 with NO BODY, unlike `rename-file`'s 200 + record.
	 *
	 * `$actorToken` attributes the rename exactly as {@see renameFile()}.
	 *
	 * @throws PenpotApiException
	 */
	public function renameProject(string $projectId, string $name, ?string $actorToken = null): void {
		$this->assertName($name);

		$this->call('rename-project', ['project' => $projectId, 'name' => $name], $actorToken);
	}

	// ── connection test ─────────────────────────────────────────────────────

	/**
	 * Cheapest authenticated call — used by "Test connection" and `occ`.
	 *
	 * Returns the visible team names so the caller can say something useful
	 * instead of "OK": an authenticated token that can see no teams is a real,
	 * ordinary state here (saga §6.12), not an error.
	 *
	 * @return list<string>
	 *
	 * @throws PenpotApiException
	 */
	public function ping(): array {
		$names = [];

		foreach ($this->getTeams() as $team) {
			$name = $team['name'] ?? null;
			if (is_string($name)) {
				$names[] = $name;
			}
		}

		return $names;
	}

	// ── the chokepoint ──────────────────────────────────────────────────────

	/**
	 * Issue one RPC command and decode its response.
	 *
	 * @param string $command The RPC command name, e.g. `get-teams`.
	 * @param array<string, string> $args Logical argument names — translated to
	 *                                    wire params through {@see PARAMS}, never
	 *                                    passed through raw.
	 *
	 * @return mixed The decoded body (`null` for a 204).
	 *
	 * @throws PenpotApiException
	 */
	protected function call(string $command, array $args = [], ?string $actorToken = null): mixed {
		$url = $this->getBaseUrl() . self::RPC_PATH . $command;
		$body = $this->wireParams($command, $args);

		// A write may attribute to the acting user's personal token (saga §6.18);
		// everything else — and any write with no personal token — uses the
		// service account. `$actorToken === null` is the ordinary case.
		$token = $actorToken ?? $this->getToken();

		try {
			$response = $this->clientService->newClient()->post($url, [
				'headers' => [
					'Authorization' => 'Token ' . $token,
					'Content-Type' => 'application/json',
					// DO NOT ADD `Accept: application/json`. It looks like the
					// obvious, tidy thing to send and it silently breaks every
					// response. Penpot CONTENT-NEGOTIATES: ask for JSON and it
					// answers plain camelCase JSON (`teamName`, `isDefault`)
					// instead of Transit (`~:team-name`, `~:is-default`).
					//
					// Two things then go wrong at once, neither of them loudly:
					//   1. every key lookup in this class misses, because they
					//      are all kebab-case; and
					//   2. Transit::decode() mangles the shape — a plain JSON
					//      object has no `"^ "` map marker, so it is walked as a
					//      LIST and comes back with numeric keys 0..n.
					// Verified live: with the header, `$record['team-name']` is
					// missing and the keys are `0,1,2,…`; without it, they are
					// `id, team-id, created-at, …` as the decoder expects.
					//
					// Transit is not a quirk we tolerate — it is the format this
					// client is built for, and the only one carrying the type
					// tags (`~u` uuid, `~m` instant) the decoder relies on.
				],
				// JSON_FORCE_OBJECT is load-bearing, not cosmetic. A no-arg command
				// has an empty param array, and `json_encode([])` renders `[]` — a
				// JSON *array*. Penpot's Clojure handler tries to conj that into a
				// param map and dies with HTTP 500 `:server-error` / "Vector arg to
				// map conj must be a pair". Confirmed live: `[]` → 500, `{}` → 200
				// on `get-teams`. Every no-arg command (get-teams, get-all-projects)
				// would fail without this, while every command WITH params would
				// work — so it looks like an auth or connectivity problem rather
				// than an encoding one.
				'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
				'timeout' => self::TIMEOUT,
				// Penpot answers 4xx with a body worth reading (it names the
				// missing param), so never let the HTTP layer throw it away.
				'http_errors' => false,
			]);
		} catch (LocalServerException $e) {
			// Nextcloud's own egress guard. Common in a homelab, where Penpot is
			// reached at an in-cluster address — so name the setting explicitly.
			throw new PenpotApiException(
				'Nextcloud refused to connect to a local address. Set `allow_local_remote_servers` '
				. 'if Penpot is reachable only in-cluster. (' . $e->getMessage() . ')',
				0,
				$e,
				PenpotApiException::KIND_UNREACHABLE,
			);
		} catch (\Throwable $e) {
			throw new PenpotApiException(
				'Could not reach Penpot at ' . $url . ': ' . $e->getMessage(),
				0,
				$e,
				PenpotApiException::KIND_UNREACHABLE,
			);
		}

		return $this->decodeResponse($command, $response);
	}

	/**
	 * Translate logical argument names into the exact wire params for a command.
	 *
	 * @param array<string, string> $args
	 *
	 * @return array<string, string>
	 *
	 * @throws PenpotApiException on an unknown command or an unmapped argument —
	 *                            both are programmer errors, and both would
	 *                            otherwise surface as a confusing Penpot 400.
	 */
	private function wireParams(string $command, array $args): array {
		if (!isset(self::PARAMS[$command])) {
			throw new PenpotApiException(
				sprintf('Unknown Penpot command "%s" — add it to PenpotClient::PARAMS once its shape is confirmed live.', $command),
				0,
				null,
				PenpotApiException::KIND_VALIDATION,
			);
		}

		$table = self::PARAMS[$command];
		$out = [];

		foreach ($args as $logical => $value) {
			if (!isset($table[$logical])) {
				throw new PenpotApiException(
					sprintf('Command "%s" has no parameter "%s" in the param table.', $command, $logical),
					0,
					null,
					PenpotApiException::KIND_VALIDATION,
				);
			}

			$out[$table[$logical]] = $value;
		}

		return $out;
	}

	/**
	 * Turn an HTTP response into a decoded body, or the right typed exception.
	 *
	 * @throws PenpotApiException
	 */
	private function decodeResponse(string $command, IResponse $response): mixed {
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();

		// STATUS FIRST, ALWAYS. An earlier version short-circuited on an empty
		// body before checking the status, which turned any empty-bodied failure
		// — a 502 from a proxy, a 500 that logged instead of rendering — into a
		// successful `null`. An empty body only means "no content" when the
		// status says the request succeeded.
		if ($status < 200 || $status >= 300) {
			throw $this->errorFor($command, $status, $body);
		}

		if ($status === 204 || $body === '') {
			// `rename-project` answers exactly this way (saga §6.38).
			return null;
		}

		return $this->transit->decode($body);
	}

	/**
	 * Build the typed exception for a non-2xx response, preserving Penpot's own
	 * error code (`object-not-found`, `params-validation`, …) for logs.
	 */
	private function errorFor(string $command, int $status, string $body): PenpotApiException {
		$penpotCode = null;

		try {
			$decoded = $this->transit->decode($body);
			if (is_array($decoded) && isset($decoded['code']) && is_string($decoded['code'])) {
				$penpotCode = $decoded['code'];
			}
		} catch (PenpotApiException) {
			// A non-Transit error body (a proxy's HTML 502, say). The status code
			// is still meaningful, so classify on that alone rather than masking
			// the real failure behind a decode error.
		}

		$kind = match (true) {
			$status === 401 => PenpotApiException::KIND_UNAUTHORIZED,
			$status === 403 => PenpotApiException::KIND_FORBIDDEN,
			$status === 404 => PenpotApiException::KIND_NOT_FOUND,
			$penpotCode === 'object-not-found' => PenpotApiException::KIND_NOT_FOUND,
			$status === 400 => PenpotApiException::KIND_VALIDATION,
			$status >= 500 => PenpotApiException::KIND_UNREACHABLE,
			default => PenpotApiException::KIND_PROTOCOL,
		};

		$this->logger->warning('Penpot command {command} failed with {status}', [
			'command' => $command,
			'status' => $status,
			'penpotCode' => $penpotCode,
			'app' => Application::APP_ID,
		]);

		return new PenpotApiException(
			sprintf(
				'Penpot command "%s" failed (HTTP %d%s).',
				$command,
				$status,
				$penpotCode !== null ? ', ' . $penpotCode : '',
			),
			$status,
			null,
			$kind,
			$penpotCode,
		);
	}

	// ── shaping helpers ─────────────────────────────────────────────────────

	/**
	 * Coerce a decoded body to a list of records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function records(mixed $decoded): array {
		if (!is_array($decoded)) {
			return [];
		}

		$out = [];

		foreach ($decoded as $row) {
			if (is_array($row)) {
				/** @var array<string, mixed> $row */
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Coerce a decoded body to a single record.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PenpotApiException
	 */
	private function record(mixed $decoded): array {
		if (!is_array($decoded)) {
			throw new PenpotApiException(
				'Expected a record from Penpot but got ' . get_debug_type($decoded),
				0,
				null,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Penpot's one real name rule: `[:string {:min 1, :max 250}]`, enforced
	 * server-side (confirmed live, saga §6.38/§6.54).
	 *
	 * Checking here is a courtesy — a better message and a saved round trip —
	 * not the only defence. The `/` guard is deliberately NOT here: it depends on
	 * the mapping's folder mode (saga §6.53), which this class knows nothing
	 * about. It belongs to the pull.
	 *
	 * @throws PenpotApiException
	 */
	private function assertName(string $name): void {
		$trimmed = trim($name);

		if ($trimmed === '') {
			throw new PenpotApiException(
				'A Penpot name cannot be empty.',
				0,
				null,
				PenpotApiException::KIND_VALIDATION,
			);
		}

		if (mb_strlen($trimmed) > 250) {
			throw new PenpotApiException(
				'A Penpot name cannot be longer than 250 characters.',
				0,
				null,
				PenpotApiException::KIND_VALIDATION,
			);
		}
	}

	// ── configuration ───────────────────────────────────────────────────────

	/**
	 * @throws PenpotApiException if no base URL is configured.
	 */
	private function getBaseUrl(): string {
		$url = rtrim($this->config->getValueString(Application::APP_ID, InstanceSettings::KEY_URL, ''), '/');

		if ($url === '') {
			throw new PenpotApiException(
				'No Penpot base URL is configured. Set one with `occ penpot_sync:set-url <url>`.',
				0,
				null,
				PenpotApiException::KIND_UNCONFIGURED,
			);
		}

		return $url;
	}

	/**
	 * The service-account token, decrypted.
	 *
	 * REQUIRED, NOT OPTIONAL (saga §6.18): the service account is the only thing
	 * that reads. A personal token attributes writes, but never drives a pull.
	 *
	 * @throws PenpotApiException if absent or undecryptable.
	 */
	private function getToken(): string {
		$stored = $this->config->getValueString(Application::APP_ID, self::KEY_TOKEN, '');

		if ($stored === '') {
			throw new PenpotApiException(
				'No Penpot service-account token is configured.',
				0,
				null,
				PenpotApiException::KIND_UNCONFIGURED,
			);
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable $e) {
			throw new PenpotApiException(
				'The stored Penpot token could not be decrypted. Set it again.',
				0,
				$e,
				PenpotApiException::KIND_UNCONFIGURED,
			);
		}
	}
}
