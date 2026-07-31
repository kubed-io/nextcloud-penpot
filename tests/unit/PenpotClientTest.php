<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Unit;

use OCA\PenpotSync\Exception\PenpotApiException;
use OCA\PenpotSync\Service\PenpotClient;
use OCA\PenpotSync\Service\Transit;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `PenpotClient` — the param table and the guards that run before the wire.
 *
 * WHAT THIS SUITE IS FOR, AND WHAT IT DELIBERATELY IS NOT. The transport is
 * exercised against a REAL Penpot in the integration suite, because a mock of a
 * protocol we have repeatedly misread would only encode the misreading (saga
 * §6.26 is the cautionary tale — a confident conclusion drawn without calling
 * the thing). So this suite covers the parts that are pure logic and must be
 * right *before* a request is built:
 *
 *   - the param table, which exists because Penpot has FOUR different parameter
 *     conventions across four commands and no rule connecting them (saga §6.54);
 *   - the name guard, which saves a round trip on a rule Penpot enforces anyway;
 *   - the unconfigured states, which must be a clear message rather than a
 *     confusing failure deep in the HTTP stack.
 */
final class PenpotClientTest extends TestCase {
	/** @var IAppConfig&\PHPUnit\Framework\MockObject\Stub */
	private IAppConfig $config;
	/** @var ICrypto&\PHPUnit\Framework\MockObject\Stub */
	private ICrypto $crypto;
	private PenpotClient $client;

	protected function setUp(): void {
		parent::setUp();

		// STUBS, not mocks: nothing here asserts on how a collaborator was
		// called — the assertions are all on what the client RETURNS or REFUSES.
		// PHPUnit 12 emits a notice for a mock with no configured expectations,
		// and it is right to: a mock says "I care about the interaction", which
		// would be a false claim here.
		$this->config = $this->createStub(IAppConfig::class);
		$this->crypto = $this->createStub(ICrypto::class);

		$this->client = new PenpotClient(
			$this->config,
			$this->crypto,
			$this->createStub(IClientService::class),
			new Transit(),
			$this->createStub(LoggerInterface::class),
		);
	}

	/**
	 * Reach the private translator directly. It is the single most consequential
	 * piece of logic in the class and it is deliberately not public — testing it
	 * through a mocked HTTP stack would assert on the mock, not on the table.
	 *
	 * @param array<string, string|bool|list<string>> $args
	 *
	 * @return array<string, string|bool|list<string>>
	 */
	private function wireParams(string $command, array $args): array {
		$method = new \ReflectionMethod(PenpotClient::class, 'wireParams');
		$method->setAccessible(true);

		/** @var array<string, string|bool|list<string>> $result */
		$result = $method->invoke($this->client, $command, $args);

		return $result;
	}

	// ── the param table (saga §6.54) ────────────────────────────────────────

	/**
	 * THE HEADLINE CASE. `rename-file` takes the file id under bare `id`, while
	 * every neighbouring command uses a qualified name. Confirmed live: sending
	 * `file-id` returns HTTP 400 `:params-validation` with `missing-key [:id]`.
	 */
	public function testRenameFileSendsTheIdUnderPlainId(): void {
		self::assertSame(
			['id' => 'abc', 'name' => 'New Name'],
			$this->wireParams('rename-file', ['file' => 'abc', 'name' => 'New Name']),
		);
	}

	/**
	 * The four confirmed conventions, in one place. If this test ever needs
	 * "fixing" by relaxing it, the fix is wrong — each row was established by
	 * calling the command live.
	 *
	 * @return iterable<string, array{string, array<string, string|list<string>>, array<string, string|list<string>>}>
	 */
	public static function paramTableProvider(): iterable {
		yield 'rename-file uses bare id' => [
			'rename-file', ['file' => 'f1', 'name' => 'N'], ['id' => 'f1', 'name' => 'N'],
		];
		yield 'rename-project also uses bare id' => [
			'rename-project', ['project' => 'p1', 'name' => 'N'], ['id' => 'p1', 'name' => 'N'],
		];
		yield 'create-project uses kebab team-id' => [
			'create-project', ['team' => 't1', 'name' => 'N'], ['team-id' => 't1', 'name' => 'N'],
		];
		yield 'get-project-files uses kebab project-id' => [
			'get-project-files', ['project' => 'p1'], ['project-id' => 'p1'],
		];
		yield 'get-team-deleted-files uses kebab team-id' => [
			'get-team-deleted-files', ['team' => 't1'], ['team-id' => 't1'],
		];
		yield 'move-files uses kebab project-id and a set under ids' => [
			'move-files', ['project' => 'p1', 'files' => ['f1', 'f2']], ['project-id' => 'p1', 'ids' => ['f1', 'f2']],
		];
		// KEBAB, and the saga said camel. §6.28 recorded `duplicate-file` as taking
		// `fileId`; the live schema (§C6.8) says `file-id`. This row is the
		// corrected one, and it is here because a wrong row in a table nobody
		// re-reads is indistinguishable from a right one until something 400s.
		yield 'duplicate-file uses kebab file-id, correcting §6.28' => [
			'duplicate-file', ['file' => 'f1', 'name' => 'N'], ['file-id' => 'f1', 'name' => 'N'],
		];
		// The trash surface, all four confirmed live in §C6.11. `create-file` takes
		// kebab project-id; `delete-file` takes a BARE id like rename-file; and the
		// two team-scoped commands take a SET under `ids`, like move-files.
		yield 'create-file uses kebab project-id' => [
			'create-file', ['project' => 'p1', 'name' => 'N'], ['project-id' => 'p1', 'name' => 'N'],
		];
		yield 'delete-file uses a bare id' => [
			'delete-file', ['file' => 'f1'], ['id' => 'f1'],
		];
		yield 'restore-deleted-team-files uses team-id + ids' => [
			'restore-deleted-team-files', ['team' => 't1', 'files' => ['f1']], ['team-id' => 't1', 'ids' => ['f1']],
		];
		yield 'permanently-delete-team-files uses team-id + ids' => [
			'permanently-delete-team-files', ['team' => 't1', 'files' => ['f1']], ['team-id' => 't1', 'ids' => ['f1']],
		];
		// THE ONE THAT DISAGREES WITH ITS OWN SIBLING. §1918: `import-binfile`
		// takes kebab (`project-id`) while `export-binfile` takes camel (`fileId`)
		// — the two halves of the same feature, on the same server, disagreeing.
		yield 'export-binfile uses CAMEL fileId, not kebab' => [
			'export-binfile',
			['file' => 'f1', 'libraries' => false, 'assets' => false],
			['fileId' => 'f1', 'includeLibraries' => false, 'embedAssets' => false],
		];
		yield 'no-arg commands send nothing' => [
			'get-teams', [], [],
		];
	}

	/**
	 * @param array<string, string|bool|list<string>> $args
	 * @param array<string, string|bool|list<string>> $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('paramTableProvider')]
	public function testTheParamTableMatchesWhatPenpotConfirmedLive(
		string $command,
		array $args,
		array $expected,
	): void {
		self::assertSame($expected, $this->wireParams($command, $args));
	}

	/**
	 * Passing a wire param name directly is a programmer error, and it must fail
	 * HERE rather than as a puzzling Penpot 400 — that indirection is the entire
	 * reason the table exists.
	 */
	public function testAWireParamNamePassedDirectlyIsRefused(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/has no parameter "file-id"/');

		$this->wireParams('rename-file', ['file-id' => 'abc']);
	}

	public function testAnUnknownCommandIsRefused(): void {
		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/Unknown Penpot command/');

		$this->wireParams('delete-everything', []);
	}

	// ── the name guard ──────────────────────────────────────────────────────

	public function testAnEmptyNameIsRefusedBeforeAnyRequest(): void {
		$this->givenConfigured();

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/cannot be empty/');

		$this->client->renameFile('abc', '   ');
	}

	public function testAnOverlongNameIsRefusedBeforeAnyRequest(): void {
		$this->givenConfigured();

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/250/');

		$this->client->renameFile('abc', str_repeat('x', 251));
	}

	/**
	 * A "/" is NOT rejected here. Whether it is legal depends on the mapping's
	 * folder mode (saga §6.53), which this class knows nothing about — so the
	 * guard belongs to the pull, and putting it here would silently break
	 * `keyed` mode later.
	 */
	public function testASlashIsNotTheClientsBusiness(): void {
		$method = new \ReflectionMethod(PenpotClient::class, 'assertName');
		$method->setAccessible(true);

		$method->invoke($this->client, 'foo/bar');

		self::assertTrue(true, 'assertName must not reject "/" — folder mode decides that.');
	}

	// ── unconfigured states ─────────────────────────────────────────────────

	public function testAMissingBaseUrlIsAClearMessage(): void {
		$this->config->method('getValueString')->willReturn('');

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/No Penpot base URL is configured/');

		$this->client->getTeams();
	}

	public function testAMissingTokenIsAClearMessage(): void {
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => $key === 'penpot_url' ? 'https://penpot.example.com' : '');

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/No Penpot service-account token/');

		$this->client->getTeams();
	}

	public function testAnUndecryptableTokenAsksForItToBeSetAgain(): void {
		$this->givenConfigured();
		$this->crypto->method('decrypt')->willThrowException(new \RuntimeException('bad key'));

		$this->expectException(PenpotApiException::class);
		$this->expectExceptionMessageMatches('/could not be decrypted/');

		$this->client->getTeams();
	}

	/** All three unconfigured states share one kind, so callers branch once. */
	public function testUnconfiguredStatesShareOneKind(): void {
		$this->config->method('getValueString')->willReturn('');

		try {
			$this->client->getTeams();
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			self::assertSame(PenpotApiException::KIND_UNCONFIGURED, $e->getKind());
			self::assertFalse($e->isRetryable(), 'a setup problem never fixes itself on retry');
		}
	}

	// ── response handling ───────────────────────────────────────────────────

	/**
	 * REGRESSION — an earlier version short-circuited on an EMPTY BODY before
	 * checking the status, so a 502 from a proxy or a 500 that logged instead of
	 * rendering came back as a successful `null`. An empty body only means "no
	 * content" when the status says the request succeeded.
	 *
	 * Driven through the private decoder directly: the point is the status/body
	 * precedence, and routing it through a mocked HTTP stack would assert on the
	 * mock rather than on that rule.
	 */
	public function testAnEmptyBodyOnAFailureStatusIsNotTreatedAsSuccess(): void {
		$this->expectException(PenpotApiException::class);

		$this->decodeResponse(502, '');
	}

	public function testAnEmptyBodyOnA204IsNoContent(): void {
		self::assertNull($this->decodeResponse(204, ''));
	}

	public function testAnEmptyBodyOnA200IsNoContent(): void {
		self::assertNull($this->decodeResponse(200, ''));
	}

	public function testAFailureStatusCarriesItsKind(): void {
		try {
			$this->decodeResponse(401, '');
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			self::assertSame(PenpotApiException::KIND_UNAUTHORIZED, $e->getKind());
		}
	}

	/** Drive the private response decoder with a stubbed IResponse. */
	private function decodeResponse(int $status, string $body): mixed {
		$response = $this->createStub(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$method = new \ReflectionMethod(PenpotClient::class, 'decodeResponse');
		$method->setAccessible(true);

		return $method->invoke($this->client, 'get-teams', $response);
	}

	// ── the event stream (saga §5.1) ────────────────────────────────────────

	/**
	 * The real transcript from §5.5, byte for byte: two `progress` events and an
	 * `end` whose payload is a Transit tagged value in MAP form.
	 *
	 * That last shape is the one that bit us. `{"~#uri": "…"}` is the only Transit
	 * payload in the app that is a genuine JSON object, so the decoder's
	 * plain-JSON guard used to reject it — and the message it produced blamed a
	 * content-negotiation header that was never sent.
	 */
	public function testTheAssetUrlIsReadFromTheEndEvent(): void {
		$stream = "event: progress\n"
			. "data: {\"~:section\":\"~:file\",\"~:name\":\"My firsty\"}\n"
			. "\n"
			. "event: progress\n"
			. "data: {\"~:section\":\"~:page\",\"~:name\":\"Page 1\"}\n"
			. "\n"
			. "event: end\n"
			. "data: {\"~#uri\":\"https://penpot.example.com/assets/by-id/75b356e7\"}\n\n";

		self::assertSame('https://penpot.example.com/assets/by-id/75b356e7', $this->assetUrlFrom($stream));
	}

	/**
	 * THE HTTP-200-WITH-AN-ERROR-INSIDE TRAP (saga §5.1/§6.20). The transport
	 * succeeded; the export did not. Anything that reads only the status code
	 * calls this a success and stores whatever came back.
	 */
	public function testAnErrorEventIsAFailureEvenThoughTheRequestSucceeded(): void {
		$stream = "event: progress\ndata: {\"~:section\":\"~:file\"}\n\n"
			. "event: error\n"
			. "data: {\"~:type\":\"~:server-error\",\"~:code\":\"~:unexpected\",\"~:hint\":\"Invalid argument. (Service: S3)\"}\n\n";

		try {
			$this->assetUrlFrom($stream);
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			// The HINT is what reaches the user — §5.2's whole debugging story
			// started from that string, and dropping it for a generic message
			// would have cost the S3 checksum diagnosis.
			self::assertStringContainsString('Invalid argument. (Service: S3)', $e->getMessage());
		}
	}

	/**
	 * A stream that stops without `end` or `error` — what a proxy timeout looks
	 * like from here. Silence must not read as success, because the alternative
	 * is an empty asset URL and a confusing failure one layer down.
	 */
	public function testAStreamThatEndsWithoutAnEndEventIsAFailure(): void {
		$this->expectException(PenpotApiException::class);

		$this->assetUrlFrom("event: progress\ndata: {\"~:section\":\"~:file\"}\n\n");
	}

	/** CRLF line endings and a repeated `data:` field are both legal SSE. */
	public function testTheReaderHandlesCrlfAndMultiLineData(): void {
		$stream = "event: end\r\ndata: {\"~#uri\":\r\ndata: \"https://penpot.example.com/a\"}\r\n\r\n";

		self::assertSame('https://penpot.example.com/a', $this->assetUrlFrom($stream));
	}

	/**
	 * An error payload that is not Transit at all (a proxy's plain-text 502 body
	 * arriving inside the stream) still has to produce a message with the reason
	 * in it, not a decode failure that hides it.
	 */
	public function testAnUndecodableErrorPayloadStillReachesTheMessage(): void {
		try {
			$this->assetUrlFrom("event: error\ndata: upstream connect error\n\n");
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			self::assertStringContainsString('upstream connect error', $e->getMessage());
		}
	}

	/**
	 * AN EMPTY BODY PLUS A REDIRECT HEADER IS PENPOT'S BACKEND ANSWERING, not a
	 * failure — the backend authenticates the asset request and hands the real
	 * location on for nginx to act on. Point the app at the backend and every
	 * export succeeds while storing nothing, so the message has to name the
	 * misconfiguration rather than describe the symptom (saga §C4.8).
	 *
	 * BOTH HEADER NAMES ARE TESTED because both are real and neither is ours to
	 * pick: `x-accel-redirect` is what the backend sends under `fs` storage,
	 * `x-internal-redirect` is nginx's echo of whatever it resolved. Keying off
	 * one name passed against our own instance and would have gone quiet against
	 * a stock docker install.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('redirectHeaders')]
	public function testAnEmptyBodyWithARedirectHeaderNamesTheBackendMistake(string $header, string $value): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('');
		$response->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === $header ? $value : '',
		);

		try {
			$this->fetchAssetWith($response);
			self::fail('expected a PenpotApiException');
		} catch (PenpotApiException $e) {
			self::assertStringContainsString('BACKEND', $e->getMessage());
			self::assertStringContainsString('frontend', $e->getMessage());
		}
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function redirectHeaders(): array {
		return [
			'fs storage — the backend\'s own header' => ['x-accel-redirect', '/internal/assets/ab/cdef'],
			'nginx\'s echo of what it resolved' => ['x-internal-redirect', 'https://storage.example.com/signed'],
		];
	}

	/**
	 * …but an empty body with no such header is a different problem, and must not
	 * be mislabelled as one. Blaming the URL for penpot#7649's empty export would
	 * send someone to change a setting that was right all along.
	 */
	public function testAnEmptyBodyWithoutTheHeaderIsNotBlamedOnTheUrl(): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('');
		$response->method('getHeader')->willReturn('');

		self::assertSame('', $this->fetchAssetWith($response));
	}

	/** Drive the private asset fetch against a canned response. */
	private function fetchAssetWith(IResponse $response): string {
		$http = $this->createStub(IClient::class);
		$http->method('get')->willReturn($response);

		$service = $this->createStub(IClientService::class);
		$service->method('newClient')->willReturn($http);

		// The fetch sends the token, so a configured one has to exist or the
		// "unconfigured" guard fires first and hides what is being tested.
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('sealed');
		$crypto = $this->createStub(ICrypto::class);
		$crypto->method('decrypt')->willReturn('a-token');

		$client = new PenpotClient(
			$config,
			$crypto,
			$service,
			new Transit(),
			$this->createStub(LoggerInterface::class),
		);

		$method = new \ReflectionMethod(PenpotClient::class, 'fetchAsset');
		$method->setAccessible(true);

		/** @var string $body */
		$body = $method->invoke($client, 'file-1', 'https://penpot.example.com/assets/by-id/abc');

		return $body;
	}

	/** Drive the private SSE reader. */
	private function assetUrlFrom(string $stream): string {
		$method = new \ReflectionMethod(PenpotClient::class, 'assetUrlFrom');
		$method->setAccessible(true);

		/** @var string $url */
		$url = $method->invoke($this->client, 'file-1', $stream);

		return $url;
	}

	private function givenConfigured(): void {
		$this->config->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key): string => $key === 'penpot_url'
				? 'https://penpot.example.com'
				: 'encrypted-token');
	}
}
