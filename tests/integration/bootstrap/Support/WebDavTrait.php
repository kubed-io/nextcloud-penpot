<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Tests\Integration\Support;

use GuzzleHttp\Client;

/**
 * WebDAV transport (Guzzle, basic-auth as the admin user) — the channel that
 * performs FILE-MANAGER GESTURES the way a browser or the desktop client does.
 *
 * ## WHY THE SUITE NEEDED THIS AT ALL
 *
 * Copy, move and rename are three of the app's write-back paths, and all three
 * are driven by events Nextcloud emits from its Files API. Nothing in `occ`
 * performs any of those gestures, so until now the suite could configure the app
 * and pull with it, but never *use* it: copy-design.feature, move-design.feature and
 * rename-design.feature were all @todo for want of a way to press the button, while the
 * code they describe shipped and was only ever exercised by hand.
 *
 * That gap has a cost on the record. A `move-files` bug was believed for an hour
 * with no red test to contradict it (saga §C6.8); a copy that silently failed to
 * record its id reached a human before it reached a test (§C6.9); and a copy to
 * the team root did nothing at all in Penpot while its unit test passed, because
 * the mock had been handed a shape the resolver never produces (§C6.10). Every
 * one of those is the kind of thing a real gesture against a real server catches
 * on the first run.
 *
 * Ported from nextcloud-n8n, whose suite has been carrying CopySteps, MoveSteps,
 * RenameSteps and more on exactly this transport.
 *
 * Composed into {@see \OCA\PenpotSync\Tests\Integration\FeatureContext};
 * reads the shared `$dav` client + `$ncBaseUrl` / `$ncUser` / `$ncPass`.
 */
trait WebDavTrait {
	private function davClient(): Client {
		if ($this->dav === null) {
			$this->dav = new Client([
				'base_uri' => $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/',
				'auth' => [$this->ncUser, $this->ncPass],
				'http_errors' => false,
				'timeout' => 30,
			]);
		}
		return $this->dav;
	}

	/**
	 * Assert an HTTP response status is in $allowed, throwing a plain, legible
	 * exception otherwise. Deliberately NOT a PHPUnit assertion: PHPUnit 12's
	 * failure exporter reaches into PHPUnit\TextUI\Configuration\Registry, which
	 * is null under Behat (no TextUI bootstrap), so a failing PHPUnit assertion
	 * here throws an opaque "Registry::get(): ... null returned" TypeError that
	 * masks the real status. A RuntimeException shows the actual code + body.
	 *
	 * @param list<int> $allowed
	 */
	private function assertStatus(\Psr\Http\Message\ResponseInterface $res, array $allowed, string $what): void {
		$code = $res->getStatusCode();
		if (!in_array($code, $allowed, true)) {
			throw new \RuntimeException("$what failed: HTTP $code (expected " . implode('/', $allowed) . ")\n" . (string)$res->getBody());
		}
	}

	/** Create a top-level folder in the admin's files root (idempotent). */
	private function davMkdir(string $folder): void {
		// 201 created, 405 already exists — both are fine for our purposes.
		$this->assertStatus($this->davClient()->request('MKCOL', rawurlencode($folder)), [201, 405], "MKCOL $folder");
		if (!in_array($folder, $this->createdFolders, true)) {
			$this->createdFolders[] = $folder;
		}
	}

	/**
	 * MKCOL at any depth under the user's files root, unlike {@see davMkdir()}
	 * which only makes a top-level folder and registers it for teardown.
	 *
	 * This is the folder half of "+ New": a user making a subfolder inside a
	 * mapped folder, which `create-project.feature` requires to stay an ORDINARY
	 * folder.
	 */
	private function davMkcol(string $path): void {
		// 201 created, 405 already exists — both leave the folder there, which is
		// all any caller wants.
		$this->assertStatus($this->davClient()->request('MKCOL', $this->davEncode($path)), [201, 405], "MKCOL $path");
	}

	/** PUT file content at a path under the user's files root. */
	private function davPut(string $path, string $body): void {
		$this->assertStatus($this->davClient()->request('PUT', $this->davEncode($path), ['body' => $body]), [201, 204], "PUT $path");
	}

	/**
	 * The immediate children of a folder, as paths under the files root.
	 *
	 * Depth 1 lists the folder itself first, and core spells that entry with a
	 * trailing slash and the same href as the request — so it is dropped by
	 * comparing the decoded path rather than by position, which a differently
	 * ordered server would get wrong.
	 *
	 * @return list<string>
	 */
	private function davChildren(string $folder): array {
		$folder = trim($folder, '/');
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($folder), [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:">'
				. '<d:prop><d:resourcetype/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "PROPFIND $folder");
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');

		$root = '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/';
		$out = [];
		foreach ($doc->xpath('//d:response/d:href') ?: [] as $href) {
			$path = trim(rawurldecode((string)$href), '/');
			$prefix = trim(rawurldecode($root), '/');
			if (!str_starts_with($path, $prefix)) {
				continue;
			}
			$rel = trim(substr($path, strlen($prefix)), '/');
			if ($rel === '' || $rel === $folder) {
				continue;
			}
			$out[] = $rel;
		}
		return $out;
	}

	/** GET a file's content. */
	private function davGet(string $path): string {
		$res = $this->davClient()->request('GET', $this->davEncode($path));
		$this->assertStatus($res, [200], "GET $path");
		return (string)$res->getBody();
	}

	/** True if a file exists (HEAD 200). */
	private function davExists(string $path): bool {
		return $this->davClient()->request('HEAD', $this->davEncode($path))->getStatusCode() === 200;
	}

	/** MOVE (rename) a file within the user's files root. */
	private function davMove(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "MOVE $from → $to");
	}

	/** MOVE a file, returning the raw status (so move-refused scenarios can inspect it). */
	private function davMoveStatus(string $from, string $to): int {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		return $this->davClient()->request('MOVE', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		])->getStatusCode();
	}

	/** COPY a file within the user's files root (fires NodeCopiedEvent in NC). */
	private function davCopy(string $from, string $to): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/files/' . rawurlencode($this->ncUser) . '/' . $this->davEncode($to);
		$res = $this->davClient()->request('COPY', $this->davEncode($from), [
			'headers' => ['Destination' => $dest, 'Overwrite' => 'F'],
		]);
		$this->assertStatus($res, [201, 204], "COPY $from → $to");
	}

	/** DELETE a file (asserting success → trash). */
	private function davDelete(string $path): void {
		$this->assertStatus($this->davClient()->request('DELETE', $this->davEncode($path)), [204, 200], "DELETE $path");
	}

	/** DELETE a file, returning the raw status (so abort scenarios can inspect it). */
	private function davDeleteStatus(string $path): int {
		return $this->davClient()->request('DELETE', $this->davEncode($path))->getStatusCode();
	}

	/**
	 * Find the trashbin entry for a file we deleted, by basename. NC trashbin DAV
	 * lives at /remote.php/dav/trashbin/<user>/trash and renames entries with a
	 * `.dNNNN` deletion-time suffix, so we match on the original basename prefix.
	 * Returns the trashbin entry filename (e.g. "Old Name.penpot.d171...") or null.
	 */
	private function trashbinPathFor(string $originalPath): ?string {
		$base = basename($originalPath);
		$href = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash';
		$res = $this->davClient()->request('PROPFIND', $href, [
			'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'
				. '<d:prop><nc:trashbin-filename/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], 'trashbin PROPFIND');
		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		foreach ($doc->xpath('//d:response') ?: [] as $resp) {
			$resp->registerXPathNamespace('d', 'DAV:');
			$resp->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
			$origName = trim((string)(($resp->xpath('.//nc:trashbin-filename') ?: [])[0] ?? ''));
			$rawHref = rawurldecode(trim((string)(($resp->xpath('d:href') ?: [])[0] ?? '')));
			if ($origName === $base && $rawHref !== '') {
				return basename(rtrim($rawHref, '/'));
			}
		}
		return null;
	}

	/** Full trashbin href for a trash entry filename. */
	private function trashHref(string $entry): string {
		return $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser) . '/trash/' . rawurlencode($entry);
	}

	/**
	 * Restore a trash entry — a MOVE into the trashbin's `restore` collection.
	 *
	 * That is the whole protocol, and it is not obvious: there is no RESTORE verb
	 * and no destination path to compute. `RestoreFolder` is an `IMoveTarget` whose
	 * `moveInto()` calls `$sourceNode->restore()`, so the target NAME is ignored
	 * entirely and the file goes back wherever it came from. Read out of the
	 * running server rather than assumed (§C6.1's rule), because the plausible
	 * alternative — MOVE the trash href to the original files path — silently
	 * copies instead of restoring and leaves the trash entry behind.
	 *
	 * This is the door that reaches `Trashbin::restore()`, which is what dispatches
	 * `NodeRestoredEvent`. A test that put the file back any other way would never
	 * fire the listener it is meant to be testing.
	 */
	private function davRestoreFromTrash(string $entry): void {
		$dest = $this->ncBaseUrl . '/remote.php/dav/trashbin/' . rawurlencode($this->ncUser)
			. '/restore/' . rawurlencode($entry);
		$res = $this->davClient()->request('MOVE', $this->trashHref($entry), [
			'headers' => ['Destination' => $dest],
		]);
		$this->assertStatus($res, [201, 204], "restore {$entry}");
	}

	/**
	 * PROPFIND a single nc:metadata-<key> on a file. Returns the property value,
	 * or null if the property is absent (404 inside the multistatus). This is the
	 * exact DAV surface view-design.feature specifies.
	 */
	private function davReadMetadata(string $path, string $key): ?string {
		$ns = 'http://nextcloud.org/ns';
		$reqBody = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:nc="' . $ns . '">'
			. '<d:prop><nc:metadata-' . $key . '/></d:prop></d:propfind>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => $reqBody,
		]);
		$this->assertStatus($res, [207], "PROPFIND $path");
		$xml = (string)$res->getBody();
		$doc = new \SimpleXMLElement($xml);
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $ns);
		// Only consider the 200-OK propstat block; a missing prop lands in a 404 block.
		foreach ($doc->xpath('//d:propstat') ?: [] as $propstat) {
			$propstat->registerXPathNamespace('d', 'DAV:');
			$propstat->registerXPathNamespace('nc', $ns);
			$status = (string)(($propstat->xpath('d:status') ?: [])[0] ?? '');
			if (!str_contains($status, '200')) {
				continue;
			}
			$node = $propstat->xpath('d:prop/nc:metadata-' . $key);
			if ($node) {
				return trim((string)$node[0]);
			}
		}
		return null;
	}

	/**
	 * The system tags on a node, by name, via PROPFIND of `{nc}system-tags`.
	 *
	 * READ THROUGH CORE, NOT THROUGH THIS APP. The claim under test is that the
	 * `penpot` marker is a real, user-visible Nextcloud tag — the same object the
	 * Files app shows and a user can assign by hand — rather than a private
	 * marker the app draws for itself. Asking the app would only prove the app
	 * agrees with the app.
	 *
	 * `occ info:file` was the obvious first try and it does not print tags at all
	 * (checked live on 33.0.4). This property does, and it is the same one the
	 * Files sidebar reads. Each tag serialises as
	 * `<nc:system-tag …>Name</nc:system-tag>` (see `OCA\DAV\SystemTag\SystemTagList`).
	 *
	 * @return list<string>
	 */
	private function davSystemTags(string $path): array {
		$ns = 'http://nextcloud.org/ns';
		$reqBody = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:nc="' . $ns . '">'
			. '<d:prop><nc:system-tags/></d:prop></d:propfind>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => $reqBody,
		]);
		$this->assertStatus($res, [207], "PROPFIND $path");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('nc', $ns);

		$names = [];
		foreach ($doc->xpath('//nc:system-tag') ?: [] as $tag) {
			$name = trim((string)$tag);
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * A DAV timestamp property on a node, as a Unix second. `getlastmodified` is
	 * RFC-1123; `{nc:}creation_time` is a Unix second already. Null when the property
	 * is absent or unset (an unset creation time reads back as 0).
	 *
	 * Read over DAV rather than through the app: the question these clocks answer is
	 * what a person in Files or a sync client SEES, and the app's own view of a node
	 * cannot answer that.
	 */
	private function davTime(string $path, string $property): ?int {
		$nc = 'http://nextcloud.org/ns';
		$prop = $property === 'creation_time' ? '<nc:creation_time/>' : '<d:' . $property . '/>';
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="' . $nc . '">'
				. '<d:prop>' . $prop . '</d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "PROPFIND $property $path");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('nc', $nc);
		$node = $doc->xpath($property === 'creation_time' ? '//nc:creation_time' : '//d:' . $property) ?: [];
		$raw = trim((string)($node[0] ?? ''));
		if ($raw === '' || $raw === '0') {
			return null;
		}
		$ts = ctype_digit($raw) ? (int)$raw : strtotime($raw);
		return $ts === false ? null : $ts;
	}

	/**
	 * The mimetype a Files client is told, read off the same PROPFIND the Files app
	 * uses.
	 *
	 * NOT `application/zip`, which is what a `.penpot` archive would otherwise be
	 * sniffed as — the whole reason the app registers a mimetype and ships a repair
	 * step for it (§C6.1). Asserted over DAV because that is where the Files app
	 * reads it from; the mapping file on disk being right proves nothing about what
	 * a client is told.
	 */
	private function davContentType(string $path): string {
		$res = $this->davClient()->request('PROPFIND', $this->davEncode($path), [
			'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
			'body' => '<?xml version="1.0"?>'
				. '<d:propfind xmlns:d="DAV:"><d:prop><d:getcontenttype/></d:prop></d:propfind>',
		]);
		$this->assertStatus($res, [207], "PROPFIND $path");

		$doc = new \SimpleXMLElement((string)$res->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		return trim((string)(($doc->xpath('//d:getcontenttype') ?: [])[0] ?? ''));
	}

	/** Percent-encode each path segment but keep the slashes. */
	private function davEncode(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
	}
}
