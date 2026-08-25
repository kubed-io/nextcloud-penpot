<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Steps;

use GuzzleHttp\Client;

/**
 * The personal connection: a user's OWN Penpot account, and the mapping it makes.
 *
 * ## WHY THIS MINTS A SECOND PENPOT PROFILE INSTEAD OF REUSING THE ADMIN TOKEN
 *
 * The whole claim of §6.45 is that a user's home root is *their* team — the one
 * on their profile's `default-team-id`, which nobody else can see. Handing the
 * service account's token to `set-personal-token` would arrange a home root
 * mapped to the SERVICE ACCOUNT's Drafts, and every assertion would pass while
 * proving the opposite of what it says: that the mapping follows the token's
 * owner. So the token here belongs to a profile this trait registers.
 *
 * Registering one costs four chained calls and no secret (saga §6.47) — the same
 * sequence the workflow uses to mint the admin token, which is why it is safe to
 * lean on: `prepare-register-profile` → `register-profile` →
 * `login-with-password` → `create-access-token`. Penpot gives every new profile a
 * default team, so the account is a personal team the instant it exists.
 *
 * ## WHY THE EMAIL IS UNIQUE PER SCENARIO
 *
 * Penpot refuses a second registration for an address, and a leg runs this arrange
 * more than once. Reusing an address would fail the second scenario for a reason
 * that has nothing to do with the app; a fresh profile is also a fresh empty team,
 * which is what makes "their Drafts holds this design" an honest assertion rather
 * than one competing with leftovers.
 *
 * ## AND WHY THE TOKEN IS CLEARED BEFORE EVERY SCENARIO
 *
 * A personal token makes the user's whole home a mapping, so leaving one behind
 * changes what LATER scenarios mean — `Create a design outside every mapping`
 * expects `Scratch` to be outside every mapping, and for a user with a token it is
 * not: it is a folder in their personal team. That is not a harness wrinkle, it is
 * the rule working, and it is exactly why the clear belongs in the shared arrange
 * ({@see ArrangeSteps::theAppIsConnectedToPenpot()}) rather than in an @AfterScenario
 * that a failing scenario could skip.
 */
trait PersonalSteps {
	/** The token minted for the acting user, so assertions can ask AS them. */
	private string $personalToken = '';

	/** @BeforeScenario */
	public function armPersonal(): void {
		$this->personalToken = '';
	}

	/**
	 * The acting Nextcloud user has a Penpot account of their own, and this app
	 * knows its token.
	 *
	 * @Given /^the user has a personal Penpot token$/
	 */
	public function theUserHasAPersonalPenpotToken(): void {
		$this->personalToken = $this->mintPenpotProfileToken();

		$res = $this->occ(sprintf(
			'penpot_sync:set-personal-token %s %s',
			escapeshellarg($this->ncUser),
			escapeshellarg($this->personalToken),
		));
		if ($res['exit'] !== 0) {
			throw new \RuntimeException("could not store the personal token:\n{$res['output']}");
		}
	}

	/**
	 * Their own team holds it — asked with THEIR token, which is the assertion.
	 *
	 * A read through the service account would answer about a team the service
	 * account can see, and the point of a personal team is that it cannot. So this
	 * goes out over the personal token and would fail if the app had mapped the
	 * home to anyone else's team.
	 *
	 * `Drafts` is spelt in the Gherkin the way a person says it; in Penpot it is
	 * the team's `is-default` project, which has a localised name and is NOT
	 * reliably called "Drafts" over the wire (§6.35). So that one name resolves by
	 * the flag and every other by its own name.
	 *
	 * @Then /^the user's personal "([^"]*)" project holds a design named "([^"]*)"$/
	 */
	public function theUsersPersonalProjectHoldsADesignNamed(string $project, string $design): void {
		$this->until(
			fn (): bool => in_array($design, $this->personalProjectFiles($project), true),
			fn (): string => sprintf(
				"expected '%s' in the user's own '%s' project; it holds: %s",
				$design,
				$project,
				implode(', ', $this->personalProjectFiles($project)) ?: '(nothing, or no such project)',
			),
		);
	}

	/** The design names in one of the personal team's projects. @return list<string> */
	private function personalProjectFiles(string $project): array {
		$team = $this->personalTeamId();
		$wantDefault = $project === 'Drafts';

		$projectId = null;
		foreach ($this->personalRpc('get-all-projects', []) as $row) {
			if (($row['team-id'] ?? null) !== $team) {
				continue;
			}
			$isDefault = filter_var($row['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN);
			if ($wantDefault ? $isDefault : (($row['name'] ?? null) === $project)) {
				$projectId = $row['id'] ?? null;
				break;
			}
		}
		if (!is_string($projectId) || $projectId === '') {
			return [];
		}

		$names = [];
		foreach ($this->personalRpc('get-project-files', ['project-id' => $projectId]) as $file) {
			if (isset($file['name']) && is_string($file['name'])) {
				$names[] = $file['name'];
			}
		}

		return $names;
	}

	/** The `is-default` team behind the personal token — the user's own. */
	private function personalTeamId(): string {
		foreach ($this->personalRpc('get-teams', []) as $team) {
			if (filter_var($team['is-default'] ?? false, FILTER_VALIDATE_BOOLEAN)
				&& isset($team['id']) && is_string($team['id'])) {
				return $team['id'];
			}
		}

		throw new \RuntimeException('the personal token names no default team — the profile mint went wrong');
	}

	/**
	 * One RPC as the personal user.
	 *
	 * @param array<string, mixed> $params
	 * @return list<array<string, mixed>>
	 */
	private function personalRpc(string $command, array $params): array {
		if ($this->personalToken === '') {
			throw new \RuntimeException('the scenario asked about the user\'s own Penpot without giving them a token');
		}

		$res = (new Client())->post(
			rtrim((string)getenv('PENPOT_URL'), '/') . '/api/rpc/command/' . $command,
			[
				'headers' => [
					'Authorization' => 'Token ' . $this->personalToken,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				],
				'body' => $params === [] ? '{}' : (string)json_encode($params),
				'http_errors' => false,
				'connect_timeout' => 10,
				'timeout' => 30,
			],
		);
		if ($res->getStatusCode() !== 200) {
			throw new \RuntimeException(sprintf(
				"Penpot %s as the personal user failed: HTTP %d\n%s",
				$command,
				$res->getStatusCode(),
				(string)$res->getBody(),
			));
		}

		$decoded = json_decode((string)$res->getBody(), true);

		return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
	}

	/**
	 * Register a fresh Penpot profile and return an access token for it.
	 *
	 * The four calls are chained — each reads the previous answer — and the third
	 * needs the session cookie the second sets, which is why this is one method
	 * rather than four steps.
	 */
	private function mintPenpotProfileToken(): string {
		$base = rtrim((string)getenv('PENPOT_URL'), '/') . '/api/rpc/command/';
		$client = new Client();
		$email = sprintf('personal-%d-%d@example.test', getmypid(), (int)(microtime(true) * 1000));
		$password = 'Integration-Tests-1';

		$call = function (string $command, array $body, string $cookie = '') use ($client, $base): array {
			$res = $client->post($base . $command, [
				'headers' => array_filter([
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
					'Cookie' => $cookie !== '' ? $cookie : null,
				]),
				'body' => (string)json_encode($body),
				'http_errors' => false,
				'connect_timeout' => 10,
				'timeout' => 30,
			]);
			if ($res->getStatusCode() !== 200) {
				throw new \RuntimeException(sprintf(
					"minting a personal Penpot profile failed at %s: HTTP %d\n%s",
					$command,
					$res->getStatusCode(),
					(string)$res->getBody(),
				));
			}

			return [
				'json' => (array)json_decode((string)$res->getBody(), true),
				'cookie' => explode(';', $res->getHeaderLine('Set-Cookie'))[0],
			];
		};

		$prepared = $call('prepare-register-profile', [
			'fullname' => 'Personal Tester', 'email' => $email, 'password' => $password,
		]);
		$token = $prepared['json']['token'] ?? null;
		if (!is_string($token) || $token === '') {
			throw new \RuntimeException('prepare-register-profile returned no token — is registration disabled?');
		}

		$call('register-profile', ['token' => $token]);

		$login = $call('login-with-password', ['email' => $email, 'password' => $password]);
		if ($login['cookie'] === '') {
			throw new \RuntimeException('login-with-password returned no session cookie');
		}

		$created = $call('create-access-token', ['name' => 'integration-personal'], $login['cookie']);
		$access = $created['json']['token'] ?? null;
		if (!is_string($access) || $access === '') {
			throw new \RuntimeException(
				"create-access-token returned no token — is 'enable-access-tokens' set in PENPOT_FLAGS?",
			);
		}

		return $access;
	}

	/**
	 * Forget any personal token this user has, so the next scenario starts from
	 * the ordinary state. See the trait docblock for why this is not optional.
	 */
	private function clearPersonalToken(): void {
		$this->occ(sprintf('penpot_sync:set-personal-token %s --clear', escapeshellarg($this->ncUser)));
	}
}
