<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
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
 * ## 4. HTTP 200 does not mean success on the binfile commands
 *
 * `export-binfile` streams Server-Sent Events, and an ERROR arrives as an event
 * *inside* a 200 response (saga §5.1/§6.20). {@see exportBinfile()} therefore has
 * its own reader and its own failure classification, and never goes through
 * {@see decodeResponse()} — which is right for every other command in the table
 * and would silently call a failed export a success.
 *
 * It is also the only command here that needs **two** requests: the stream ends
 * with an asset URL, and the bytes come from fetching that.
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
		// ── confirmed live, Chapter 1 §6.27/§6.34 (the cross-team move probe) ──
		// Note `ids` is a SET of file ids, the one command in this table whose
		// value is not a scalar — see wireParams()' widened type.
		'move-files' => ['project' => 'project-id', 'files' => 'ids'],
		// ── confirmed live, Ch2 §C6.8 (the copy probe) ──
		// KEBAB `file-id`, which CORRECTS §6.28's record of a camelCase `fileId`.
		// The row exists because that correction cost a live call to find: the
		// survey wrote the wrong casing down and nothing contradicted it until a
		// schema was read back off the server.
		'duplicate-file' => ['file' => 'file-id', 'name' => 'name'],
		// ── confirmed live, Ch2 §C6.11 (the trash probe) ──
		// `create-file` takes KEBAB `project-id`, and `name` is REQUIRED — a
		// design cannot be created nameless. Its optional `id` (a caller-supplied
		// uuid) is deliberately not offered: Penpot assigns identities, we record
		// them. Closes open question #27.
		'create-file' => ['project' => 'project-id', 'name' => 'name'],
		'delete-file' => ['file' => 'id'],
		'restore-deleted-team-files' => ['team' => 'team-id', 'files' => 'ids'],
		'permanently-delete-team-files' => ['team' => 'team-id', 'files' => 'ids'],
		// ── confirmed live, Chapter 1 §5.1/§5.4 ──
		// CAMELCASE, and that is not a slip: §1918 established that `import-binfile`
		// takes kebab (`project-id`) while `export-binfile` takes camel (`fileId`).
		// The two halves of the same feature disagree with each other. This row is
		// the whole reason the table exists.
		'export-binfile' => ['file' => 'fileId', 'libraries' => 'includeLibraries', 'assets' => 'embedAssets'],
	];

	/**
	 * Seconds to wait on an ordinary RPC call.
	 *
	 * The SSE command {@see exportBinfile()} does NOT use this — an export of a
	 * large file streams progress for far longer than any listing takes, and
	 * capping it here would turn "slow" into "failed". See EXPORT_TIMEOUT.
	 */
	private const TIMEOUT = 30;

	/**
	 * Seconds to wait on `export-binfile` and on the asset fetch that follows it.
	 *
	 * Five minutes, not thirty seconds: the server does real work per page and per
	 * shape before the stream ends, and the whole point of `sync` mode is the files
	 * worth keeping — which are the big ones. A timeout here is not a lost request,
	 * it is a wasted export the server already paid for.
	 */
	private const EXPORT_TIMEOUT = 300;

	/**
	 * A `.penpot` archive is a ZIP, so it starts with the local-file-header magic.
	 *
	 * Checked because HTTP 200 is not proof here (penpot#7649 has shipped a
	 * zero-byte "successful" export), and because the asset URL is served through
	 * a proxy that can answer an HTML error page with a 200 (saga §5.3).
	 */
	private const ZIP_MAGIC = "PK\x03\x04";

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
	 * Duplicate a design, server-side. Proven live in §C6.8: HTTP 200 with a full
	 * new file record, and the `name` IS honoured (unlike `import-binfile`, §6.20).
	 *
	 * ## NO BYTES TRAVEL, WHICH IS WHY MODE DOES NOT MATTER
	 *
	 * Penpot copies the design from its id alone. Nothing is exported, uploaded
	 * or re-imported, so a `link` file holding **zero bytes** (§C6.6) duplicates
	 * exactly as completely as a stored `sync` archive. Neither sibling app can
	 * do this — they copy by pushing the file's own content, so a pointer would
	 * have nothing to push.
	 *
	 * ## IT CANNOT CHOOSE A PROJECT
	 *
	 * There is no project parameter in the schema, so the duplicate always lands
	 * in the SOURCE design's project. A copy that must end up elsewhere is this
	 * call followed by {@see moveFiles()} — which is exactly why one Nextcloud
	 * gesture is two mechanisms (copy.feature). The returned record carries
	 * `projectId`, so the caller compares against that rather than guessing.
	 *
	 * @return array<string, mixed> the new file record, incl. `id` and `projectId`
	 *
	 * @throws PenpotApiException
	 */
	public function duplicateFile(string $fileId, string $name, ?string $actorToken = null): array {
		$this->assertName($name);

		return $this->record($this->call('duplicate-file', ['file' => $fileId, 'name' => $name], $actorToken));
	}

	/**
	 * Create a new design in a project. Confirmed live in §C6.11.
	 *
	 * `name` is required by the schema — Penpot has no nameless design — and the
	 * project is mandatory too: there is no team-level or rootless create, which
	 * is exactly why the New-menu action is only offered where a project can be
	 * resolved (create-design.feature).
	 *
	 * @return array<string, mixed> the new file record, incl. `id`
	 *
	 * @throws PenpotApiException
	 */
	public function createFile(string $projectId, string $name): array {
		$this->assertName($name);

		return $this->record($this->call('create-file', ['project' => $projectId, 'name' => $name]));
	}

	/**
	 * Move a design into Penpot's own trash. **SOFT — this is not destructive.**
	 *
	 * §6.34 recorded this as the app's one destructive call, on the belief that
	 * Penpot's trash was unreachable. §6.52 disproved that and §C6.11 confirmed it
	 * again: the design keeps its id, revision and history, stays listed by
	 * {@see deletedFiles()}, and comes back whole. It is exactly as reversible as
	 * moving a Nextcloud file to the Nextcloud trash, which is what makes it the
	 * right partner for that gesture.
	 *
	 * Penpot answers 204 with no body.
	 *
	 * @throws PenpotApiException
	 */
	public function deleteFile(string $fileId, ?string $actorToken = null): void {
		$this->call('delete-file', ['file' => $fileId], $actorToken);
	}

	/**
	 * The designs currently in a team's trash.
	 *
	 * ALSO A SAFETY DEVICE, not just a listing: it is the only sanctioned source
	 * of ids for {@see permanentlyDeleteFiles()}, which has no safety of its own.
	 * See that method.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws PenpotApiException
	 */
	public function deletedFiles(string $teamId): array {
		return $this->records($this->call('get-team-deleted-files', ['team' => $teamId]));
	}

	/**
	 * DESTROY designs. The one irreversible call in this app.
	 *
	 * ## IT DOES NOT CHECK THAT THEY ARE IN THE TRASH (saga §C6.11)
	 *
	 * The name says "permanently-delete-team-FILES", not "empty the trash", and
	 * that is exactly what it means. Proven live: a design that had been RESTORED
	 * — live, listed in its project, not deleted at all — was destroyed by
	 * passing its id here. HTTP 200, a progress event, gone.
	 *
	 * So the caller carries the entire safety burden, and there is one rule:
	 * **every id passed here must have come from a fresh {@see deletedFiles()}
	 * listing.** Not from a mirror's metadata, not from a user's selection, not
	 * from anything this app worked out for itself. An id absent from that
	 * listing means the design was already purged or someone restored it in
	 * Penpot's UI — and in both cases destroying it is not what was asked for.
	 *
	 * SSE, like the export: progress per file, then `end`.
	 *
	 * @param list<string> $fileIds ids taken from deletedFiles(), and nowhere else
	 *
	 * @throws PenpotApiException
	 */
	public function permanentlyDeleteFiles(string $teamId, array $fileIds, ?string $actorToken = null): void {
		if ($fileIds === []) {
			return;
		}

		$this->consumeEventStream(
			'permanently-delete-team-files',
			['team' => $teamId, 'files' => $fileIds],
			$actorToken,
		);
	}

	/**
	 * Bring designs back OUT of a team's trash — the exact inverse of
	 * {@see deleteFile()}, and lossless where an archive import never can be.
	 *
	 * The design returns with its **id, revision, history and deep links intact**
	 * (§6.49, re-confirmed live in §C6.11), which is why every restore path in this
	 * app must try this before it offers anything else.
	 *
	 * ## THE RETURN VALUE IS THE POINT, AND IT IS NOT A BOOLEAN
	 *
	 * `restore-deleted-team-files` answers **200 with an `end` event carrying an
	 * EMPTY SET** for an id it did not restore (§C6.11) — no error, no warning. A
	 * caller that reads the status code, or even the `end` event's existence, will
	 * cheerfully report a restore that restored nothing, and the user will go
	 * looking for a file that is not there.
	 *
	 * So this returns **the ids Penpot says it actually restored**, and the caller
	 * compares. {@see \OCA\PenpotSync\Service\RestoreService} then re-reads the
	 * trash listing on top of that, because §6.49 once saw the `end` event arrive
	 * while `deleted_at` was still set.
	 *
	 * SSE, like the export and the permanent delete.
	 *
	 * @param list<string> $fileIds
	 *
	 * @return list<string> the ids Penpot reports as restored — a SUBSET of
	 *                      `$fileIds`, and empty when nothing was restored
	 *
	 * @throws PenpotApiException
	 */
	public function restoreDeletedFiles(string $teamId, array $fileIds, ?string $actorToken = null): array {
		if ($fileIds === []) {
			return [];
		}

		$payload = $this->consumeEventStream(
			'restore-deleted-team-files',
			['team' => $teamId, 'files' => $fileIds],
			$actorToken,
		);

		return $this->idsFrom($payload);
	}

	/**
	 * Normalise an `end` payload into a list of ids.
	 *
	 * Penpot sends a Transit `~#set` of `~u<uuid>` values, which the decoder hands
	 * back as a plain list of uuid strings. The record form is tolerated too — the
	 * cost is one `is_array()` and the alternative is a restore that silently
	 * reports failure if Penpot ever enriches the event.
	 *
	 * @return list<string>
	 */
	private function idsFrom(mixed $payload): array {
		if (!is_array($payload)) {
			return [];
		}

		$ids = [];
		foreach ($payload as $entry) {
			if (is_string($entry) && $entry !== '') {
				$ids[] = $entry;
			} elseif (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
				$ids[] = $entry['id'];
			}
		}

		return $ids;
	}

	/**
	 * Create a project in a team. Confirmed live in §6.38: KEBAB `team-id`, and
	 * HTTP 200 with the **full project record** — unlike its sibling
	 * {@see renameProject()}, which answers 204 with no body at all. Two halves of
	 * one feature, disagreeing about whether a response has content; the param
	 * table above exists for the same reason.
	 *
	 * ONLY EVER CALLED ON AN EXPLICIT OPT-IN (`project-folder.feature`). Every
	 * Penpot project becomes a Nextcloud folder automatically, but a Nextcloud
	 * folder becomes a project only when someone tags it — so this method has
	 * exactly one caller, {@see ProjectFolderService::onTagged()}, and no path
	 * from the pull.
	 *
	 * `$actorToken` attributes the creation exactly as {@see renameProject()}: the
	 * project should be owned by the person who asked for it, not by the service
	 * account that happened to carry the request.
	 *
	 * @return array<string, mixed> the new project record, incl. `id`
	 *
	 * @throws PenpotApiException
	 */
	public function createProject(string $teamId, string $name, ?string $actorToken = null): array {
		$this->assertName($name);

		return $this->record($this->call('create-project', ['team' => $teamId, 'name' => $name], $actorToken));
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

	/**
	 * Re-file one or more design files into a project. Proven live in §6.27/§6.34:
	 * HTTP 204, and the destination's `team-id` follows automatically — so this one
	 * call covers a same-team re-file *and* a cross-team move.
	 *
	 * NON-DESTRUCTIVE AND REVERSIBLE. Nothing is copied, re-imported or re-id'd:
	 * the file keeps its id, its `revn` and its whole history, and dragging it back
	 * is the same call in the other direction (§6.34). That is why the user-facing
	 * drag propagates rather than being refused or silently reverted.
	 *
	 * Penpot answers 204 with no body, like `rename-project`.
	 *
	 * @param list<string> $fileIds A LIST, not any array: `ids` goes on the wire
	 *                              as a JSON array, and a gappy or keyed array
	 *                              would encode as an object instead.
	 *
	 * @throws PenpotApiException
	 */
	public function moveFiles(string $projectId, array $fileIds, ?string $actorToken = null): void {
		if ($fileIds === []) {
			// An empty set is a no-op request, and Penpot would reject it as a
			// validation error — refuse to spend the round-trip.
			return;
		}

		$this->call('move-files', ['project' => $projectId, 'files' => $fileIds], $actorToken);
	}

	// ── the export surface ──────────────────────────────────────────────────

	/**
	 * Export one design and return the real `.penpot` archive bytes.
	 *
	 * THE ONLY TWO-REQUEST COMMAND IN THIS CLASS, and the only one where HTTP 200
	 * settles nothing (saga §5.1). What actually happens:
	 *
	 *   1. `POST export-binfile` answers `text/event-stream`, not a body: a
	 *      `progress` event per file and per page, then either `error` or `end`.
	 *      **The failure arrives inside the 200.**
	 *   2. `end`'s payload is a Transit tagged value in map form carrying an asset
	 *      URL — `{"~#uri": "https://…/assets/by-id/<uuid>"}`.
	 *   3. That URL is fetched with the same token to get the ZIP.
	 *
	 * BOTH FLAGS ARE SENT FALSE. penpot#7649: `includeLibraries` **and**
	 * `embedAssets` together throw an opaque 500. Sending neither is the one
	 * combination that is unambiguously outside the bug, and it is what the live
	 * export in §5.4 was verified against. `embedAssets: true` is the obvious
	 * future upgrade — it would make the archive self-contained — but turning it
	 * on is a change to what a backup *is*, so it waits for its own probe rather
	 * than riding in on this one.
	 *
	 * @return string the ZIP bytes, verified non-empty and ZIP-shaped
	 *
	 * @throws PenpotApiException
	 */
	public function exportBinfile(string $fileId): string {
		$stream = $this->postStream('export-binfile', ['file' => $fileId, 'libraries' => false, 'assets' => false]);
		$assetUrl = $this->assetUrlFrom($fileId, $stream);
		$archive = $this->fetchAsset($fileId, $assetUrl);

		// A "successful" export that produced nothing is a real, witnessed
		// failure mode (penpot#7649), and it is the worst one to pass on: the
		// caller would store an empty file OVER a good archive and call it a
		// backup. Refuse here so the caller's error path keeps the old bytes.
		if (!str_starts_with($archive, self::ZIP_MAGIC)) {
			throw new PenpotApiException(
				sprintf(
					'Penpot exported file %s but the download was not a ZIP archive (%d bytes). '
					. 'The export may have produced an empty archive (penpot#7649), or a proxy '
					. 'answered the asset URL with an error page.',
					$fileId,
					strlen($archive),
				),
				0,
				null,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		return $archive;
	}

	/**
	 * POST any SSE command and return the raw event-stream text.
	 *
	 * Shared by the export (which reads the asset URL out of the `end` event) and
	 * by {@see consumeEventStream()} (which only needs to know the stream got
	 * there). Three commands stream today — export, permanent delete, and restore
	 * when it lands — and they all need the same non-Accept headers and the same
	 * long timeout, so they share one door.
	 *
	 * @param array<string, string|list<string>|bool> $args logical args, mapped through PARAMS
	 *
	 * @throws PenpotApiException
	 */
	private function postStream(string $command, array $args, ?string $actorToken = null): string {
		$url = $this->getBaseUrl() . self::RPC_PATH . $command;
		$body = $this->wireParams($command, $args);

		try {
			$response = $this->clientService->newClient()->post($url, [
				'headers' => [
					'Authorization' => 'Token ' . ($actorToken ?? $this->getToken()),
					'Content-Type' => 'application/json',
					// Still NO `Accept` header — see call(). The event payloads are
					// Transit exactly like every other response, and asking for JSON
					// breaks them the same way.
				],
				'body' => json_encode($body, JSON_THROW_ON_ERROR),
				'timeout' => self::EXPORT_TIMEOUT,
				'http_errors' => false,
			]);
		} catch (LocalServerException $e) {
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

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			// A non-2xx here is an ordinary RPC failure (a bad id, a bad token)
			// that never reached the streaming stage, so it classifies like one.
			throw $this->errorFor($command, $status, (string)$response->getBody());
		}

		return (string)$response->getBody();
	}

	/**
	 * Read the event stream and return the asset URL its `end` event carries.
	 *
	 * @throws PenpotApiException on an `error` event, or a stream that ends
	 *                            without one — silence is a failure too.
	 */
	private function assetUrlFrom(string $fileId, string $stream): string {
		$url = null;

		foreach ($this->events($stream) as [$name, $data]) {
			if ($name === 'error') {
				throw new PenpotApiException(
					sprintf('Penpot failed to export file %s: %s', $fileId, $this->errorHint($data)),
					0,
					null,
					PenpotApiException::KIND_PROTOCOL,
				);
			}

			if ($name === 'end') {
				$decoded = $this->transit->decode($data);
				$url = is_string($decoded) ? $decoded : null;
			}
			// `progress` events are per-file and per-page bookkeeping. They are
			// deliberately not logged: a big export emits one per shape, and the
			// only fact they carry that we do not already have is a page name.
		}

		if ($url === null || $url === '') {
			throw new PenpotApiException(
				sprintf(
					'Penpot\'s export of file %s ended without an asset URL. The stream may have been '
					. 'cut short by a proxy timeout.',
					$fileId,
				),
				0,
				null,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		return $url;
	}

	/**
	 * POST an SSE command, assert its stream reached `end` without an `error`, and
	 * return what that `end` event carried.
	 *
	 * For the two streaming commands whose answer is the work itself rather than a
	 * download — `permanently-delete-team-files` and `restore-deleted-team-files`.
	 * The export has its own reader because it needs the asset URL out of the `end`
	 * event and then a second request; these two finish inside the stream.
	 *
	 * **HTTP 200 IS NOT SUCCESS** (§5.1, and it is just as true here): an error
	 * arrives as an event INSIDE a 200 response. Anything that returns early on
	 * the status code alone would call a failed purge a completed one.
	 *
	 * **AND `end` IS NOT SUCCESS EITHER** (§C6.11). The restore answers 200 with an
	 * `end` carrying an EMPTY SET for an id it did not restore — no error, no
	 * warning. So the payload is returned rather than discarded: for the restore it
	 * is the only honest answer to "did this work", and the purge simply ignores it.
	 *
	 * @param array<string, string|list<string>|bool> $args logical args, mapped through PARAMS
	 *
	 * @return mixed the decoded `end` payload — a list of ids for both commands,
	 *               `null` when the event carried no data
	 *
	 * @throws PenpotApiException on an `error` event or a stream with no `end`
	 */
	private function consumeEventStream(string $command, array $args, ?string $actorToken = null): mixed {
		$stream = $this->postStream($command, $args, $actorToken);

		$sawEnd = false;
		$payload = null;
		foreach ($this->events($stream) as [$name, $data]) {
			if ($name === 'error') {
				throw new PenpotApiException(
					sprintf('Penpot reported an error during %s: %s', $command, $this->errorHint($data)),
					0,
					null,
					PenpotApiException::KIND_PROTOCOL,
				);
			}
			if ($name === 'end') {
				$sawEnd = true;
				$payload = $data === '' ? null : $this->transit->decode($data);
			}
		}

		if (!$sawEnd) {
			throw new PenpotApiException(
				sprintf('Penpot %s ended without an `end` event; the work may be incomplete.', $command),
				0,
				null,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		return $payload;
	}

	/**
	 * Split an SSE body into `[event, data]` pairs.
	 *
	 * Events are separated by a blank line; a `data:` field may be repeated and
	 * the parts are joined with a newline (the SSE spec's rule, and Penpot's
	 * Transit payloads are single-line today — but a payload that grew past the
	 * server's line budget would otherwise decode as truncated JSON).
	 *
	 * An event with no `event:` field defaults to `message`, per the spec.
	 *
	 * @return list<array{0:string, 1:string}>
	 */
	private function events(string $stream): array {
		$out = [];

		foreach (preg_split('/\R\R+/', trim(str_replace("\r\n", "\n", $stream))) ?: [] as $block) {
			if (trim($block) === '') {
				continue;
			}

			$name = 'message';
			$data = [];

			foreach (explode("\n", $block) as $line) {
				if (str_starts_with($line, 'event:')) {
					$name = trim(substr($line, 6));
				} elseif (str_starts_with($line, 'data:')) {
					$data[] = ltrim(substr($line, 5), ' ');
				}
			}

			$out[] = [$name, implode("\n", $data)];
		}

		return $out;
	}

	/**
	 * Pull a human-readable reason out of an `error` event's Transit payload,
	 * falling back to the raw data when it does not decode.
	 *
	 * The shape (saga §5.2): `{"~:type":"~:server-error","~:code":"~:unexpected",
	 * "~:hint":"…"}`. The hint is the only part worth showing a user; the code is
	 * worth keeping when there is no hint.
	 */
	private function errorHint(string $data): string {
		try {
			$decoded = $this->transit->decode($data);
		} catch (PenpotApiException) {
			return $data;
		}

		if (!is_array($decoded)) {
			return $data;
		}

		foreach (['hint', 'code', 'type'] as $key) {
			if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
				return $decoded[$key];
			}
		}

		return $data;
	}

	/**
	 * Fetch the archive bytes from the URL the `end` event handed us.
	 *
	 * The token is sent because the first hop is Penpot's own `/assets/by-id/…`,
	 * which authenticates and then redirects to object storage. The HTTP client
	 * drops the Authorization header across that host change on its own, which is
	 * both correct and necessary — the storage URL carries its own signature.
	 *
	 * THIS HOP MUST LAND ON PENPOT'S FRONTEND, NOT ITS BACKEND — and WE DO NOT
	 * CHOOSE WHICH. The URL comes from the `end` event, and Penpot builds it from
	 * its own `PENPOT_PUBLIC_URI`. The backend does not serve the bytes: it
	 * authenticates, then answers with **no body** and a redirect header naming
	 * where the file really is, for **nginx** to act on. So a Penpot whose public
	 * URI names its backend hands us an address that downloads nothing, no matter
	 * what `penpot_url` is set to — see the empty-body branch below, which is the
	 * only reason that is nameable instead of baffling (saga §C4.8).
	 *
	 * @throws PenpotApiException
	 */
	private function fetchAsset(string $fileId, string $url): string {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => ['Authorization' => 'Token ' . $this->getToken()],
				'timeout' => self::EXPORT_TIMEOUT,
				'http_errors' => false,
			]);
		} catch (\Throwable $e) {
			throw new PenpotApiException(
				sprintf('Could not download the exported archive for file %s: %s', $fileId, $e->getMessage()),
				0,
				$e,
				PenpotApiException::KIND_UNREACHABLE,
			);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			// Saga §5.3: a misconfigured `internalResolver` makes this 502 while
			// the export itself succeeded — so say WHICH half failed, or the next
			// person debugs the export for an hour.
			throw new PenpotApiException(
				sprintf(
					'Penpot exported file %s, but downloading the archive failed (HTTP %d). '
					. 'The export itself succeeded — this is the asset fetch.',
					$fileId,
					$status,
				),
				$status,
				null,
				$status >= 500 ? PenpotApiException::KIND_UNREACHABLE : PenpotApiException::KIND_PROTOCOL,
			);
		}

		$archive = (string)$response->getBody();

		// AUTHENTICATED FINE, AND NOTHING IN IT. Penpot's backend answers exactly
		// this way: it hands the real location to nginx in a header and lets nginx
		// serve the file. Nothing else about the connection looks wrong — the token
		// works, the export streams, the status is a success — so without naming it
		// here, the only symptom is "my backups are empty."
		if ($archive === '' && $this->redirectHeader($response) !== '') {
			throw new PenpotApiException(
				sprintf(
					'Penpot exported file %s, but the archive download returned no content. '
					. 'Penpot handed us <%s>, which reaches its BACKEND — only its frontend '
					. '(nginx) serves exported files. This address comes from Penpot\'s own '
					. 'PENPOT_PUBLIC_URI, so it must be fixed on the Penpot side.',
					$fileId,
					$url,
				),
				0,
				null,
				PenpotApiException::KIND_PROTOCOL,
			);
		}

		return $archive;
	}

	/**
	 * The "a proxy was supposed to handle this" header, whichever name it arrived
	 * under — the tell that we reached the backend directly.
	 *
	 * BOTH NAMES ARE REAL, and which one appears says which half of Penpot we hit.
	 * The backend emits `x-accel-redirect` (its `fs` object storage) or a 3xx
	 * `location` (its `s3` backend). Nginx, once it has acted on either, echoes
	 * the resolved address back as `x-internal-redirect` — a diagnostic, not an
	 * instruction. Keying off only one name means the check silently stops working
	 * against half the Penpot deployments in existence, which is worse than not
	 * having it: the failure it exists to explain would return, unexplained.
	 */
	private function redirectHeader(IResponse $response): string {
		foreach (['x-accel-redirect', 'x-internal-redirect'] as $name) {
			if ($response->getHeader($name) !== '') {
				return $response->getHeader($name);
			}
		}

		return '';
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
	 * @param array<string, string|bool|list<string>> $args Logical argument names —
	 *                                                      translated to wire params through
	 *                                                      {@see PARAMS}, never passed through raw.
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
				// THE EMPTY BODY MUST BE `{}`, NEVER `[]`, and it is load-bearing,
				// not cosmetic. A no-arg command has an empty param array, and
				// `json_encode([])` renders `[]` — a JSON *array*. Penpot's Clojure
				// handler tries to conj that into a param map and dies with HTTP 500
				// `:server-error` / "Vector arg to map conj must be a pair".
				// Confirmed live: `[]` → 500, `{}` → 200 on `get-teams`. Every no-arg
				// command (get-teams, get-all-projects) would fail without this,
				// while every command WITH params would work — so it looks like an
				// auth or connectivity problem rather than an encoding one.
				//
				// This used to be `JSON_FORCE_OBJECT`, which was correct only while
				// every param value was a scalar. That flag forces **every** array
				// in the payload to an object, including a nested one — so
				// `move-files`' `ids` set would go out as `{"0":"<uuid>"}` instead
				// of `["<uuid>"]` and Penpot would reject it. Special-casing the
				// empty body keeps the `{}` guarantee without touching the values.
				'body' => $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR),
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
	 * @param array<string, string|bool|list<string>> $args
	 *
	 * @return array<string, string|bool|list<string>>
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
