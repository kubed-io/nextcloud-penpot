<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Migration;

use OC\Core\Command\Maintenance\Mimetype\GenerateMimetypeFileBuilder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Reverses {@see RegisterMimetype} on app removal (the `<uninstall>` repair
 * step), so removing the app leaves the Nextcloud core tree as it found it —
 * the store's clean-uninstall rule (uninstall.feature). The mirror image of
 * install:
 *   1. Drop our `penpot` / `application/vnd.penpot` keys from the live config
 *      files (`config/mimetypemapping.json`, `config/mimetypealiases.json`).
 *   2. Delete the icon we copied to `core/img/filetypes/penpot.svg`.
 *   3. Re-stamp every `*.penpot` filecache row to `application/zip`.
 *   4. Regenerate `core/js/mimetypelist.js` without our alias.
 *
 * ## STEP 3 REVERTS TO `application/zip`, NOT `application/json`
 *
 * The one line that could not be copied from either sibling. Theirs revert to
 * `application/json` because that is what their mirrors have always been. Ours
 * revert to `application/zip` because that is what Penpot's own server calls a
 * `.penpot` export (§6.4, confirmed live with `curl -I`) — so an uninstalled
 * mirror is described exactly as its origin describes it.
 *
 * This is right for a `sync` mirror, which really is a ZIP. It is *wrong* for a
 * `link` mirror, which holds a JSON pointer — but no single extension-keyed
 * mimetype can be right for both, and the extension is all the detector has once
 * our mapping is gone. `zip` is the better wrong answer: the pointer is an
 * implementation detail of an app that is being removed, while the archive is
 * the thing the user is left holding and may still want to open.
 *
 * It touches **only** the system registration — never the user's `.penpot`
 * files, their metadata, the mappings, or Penpot. Idempotent and fail-soft (a
 * half-present registration reverts cleanly).
 */
final class UnregisterMimetype implements IRepairStep {
	private const APP_MIMETYPE = 'application/vnd.penpot';
	private const APP_ALIAS_KEY = self::APP_MIMETYPE;
	private const APP_ICON_NAME = 'penpot';
	private const FILE_EXT = 'penpot';

	/** What a `.penpot` file is to anyone who is not this app (saga §6.4). */
	private const FALLBACK_MIMETYPE = 'application/zip';

	public function __construct(
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove the penpot_sync mimetype + icon';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$serverRoot = \OC::$SERVERROOT;
		$configDir = \OC::$configDir;

		try {
			$this->removeJsonKey($configDir . 'mimetypemapping.json', self::FILE_EXT);
			$this->removeJsonKey($configDir . 'mimetypealiases.json', self::APP_ALIAS_KEY);
		} catch (\Throwable $e) {
			$this->logger->error('penpot_sync: failed to revert mimetype config', ['exception' => $e]);
			$output->warning('penpot_sync: could not clean config/mimetype*.json (' . $e->getMessage() . ')');
		}

		// Delete the icon we copied into core/img/filetypes/.
		$dst = $serverRoot . '/core/img/filetypes/' . self::APP_ICON_NAME . '.svg';
		if (is_file($dst) && !@unlink($dst)) {
			$output->warning('penpot_sync: could not remove ' . $dst);
		}

		// Re-stamp the filecache rows so nothing dangles on the now-removed
		// mimetype id. The detector cache is primed because we just edited the
		// on-disk config files.
		$this->detector->getAllMappings(); // primes lazy load (no public reset)
		$zipId = $this->loader->getId(self::FALLBACK_MIMETYPE);
		$touched = $this->loader->updateFilecache(self::FILE_EXT, $zipId);
		$output->info(sprintf(
			'penpot_sync: %d filecache row(s) reverted to %s',
			$touched,
			self::FALLBACK_MIMETYPE,
		));

		// Regenerate core/js/mimetypelist.js without our alias.
		try {
			$gen = new GenerateMimetypeFileBuilder();
			$js = $gen->generateFile(
				$this->detector->getAllAliases(),
				$this->detector->getAllNamings(),
			);
			@file_put_contents($serverRoot . '/core/js/mimetypelist.js', $js);
		} catch (\Throwable $e) {
			$this->logger->error('penpot_sync: failed to regenerate mimetypelist.js', ['exception' => $e]);
		}
	}

	/**
	 * Remove a single key from a JSON object file, atomically (tempfile + rename).
	 * No-op when the file or key is absent. Leaves every other entry untouched.
	 */
	private function removeJsonKey(string $path, string $key): void {
		if (!is_file($path)) {
			return;
		}
		$raw = file_get_contents($path);
		if ($raw === false || trim($raw) === '') {
			return;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !array_key_exists($key, $decoded)) {
			return;
		}
		unset($decoded[$key]);
		$encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			throw new \RuntimeException('json_encode failed for ' . $path);
		}
		$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
		if (file_put_contents($tmp, $encoded) === false) {
			throw new \RuntimeException('write failed: ' . $tmp);
		}
		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			throw new \RuntimeException('rename failed: ' . $path);
		}
	}
}
