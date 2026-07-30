<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\PenpotSync\Migration;

use OC\Core\Command\Maintenance\Mimetype\GenerateMimetypeFileBuilder;
use OCA\PenpotSync\AppInfo\Application;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Registers the `application/vnd.penpot` mimetype + its icon so a mirrored
 * `.penpot` file renders as a design in the Files row, not a generic archive.
 *
 * Ported from both siblings' `RegisterMimetype`, with two deliberate changes
 * recorded below. Runs on every install/upgrade; all four steps are idempotent:
 *   1. Merge our extension/alias mappings into the live config files
 *      (`config/mimetypemapping.json`, `config/mimetypealiases.json`) so the
 *      Detection layer + frontend resolver see them.
 *   2. Copy the SVG to `core/img/filetypes/penpot.svg` — that is where
 *      GenerateMimetypeFileBuilder enumerates icon basenames from.
 *   3. Insert the mimetype into `oc_mimetypes` and rewrite every filecache row
 *      whose name ends in `.penpot` to that id.
 *   4. Regenerate `core/js/mimetypelist.js` so the frontend map carries the alias.
 *
 * Equivalent to `occ maintenance:mimetype:update-db` + `update-js`, but inline
 * with the app's lifecycle: no human step.
 *
 * ## WHY THIS IS NEEDED AT ALL (saga §6.4)
 *
 * The `.penpot` extension is real and Penpot-specific — but Penpot's own server
 * hands the export back as `Content-Type: application/zip` (confirmed live), so
 * there is no branded mimetype to inherit. Same position both siblings were in.
 * The one real win over them: `.penpot` is a SINGLE-TOKEN extension, not a
 * compound (`.n8n.json` / `.grafana.json`), so none of the "don't simplify the
 * compound extension" fragility applies here.
 *
 * ## WHY `application/vnd.penpot`, WITH NO STRUCTURED SUFFIX
 *
 * Both siblings register `application/<vendor>+json`, which is honest for them —
 * their mirrors are always JSON. Ours are not, and the difference is the whole
 * `sync`/`link` axis (§6.22): a `sync` mirror holds a real ZIP archive, a `link`
 * mirror holds a small JSON pointer. `+json` would be a lie for half our files
 * and `+zip` for the other half, and `+zip` is the worse of the two because it
 * invites a client to try to unpack a pointer. So the type carries no structured
 * suffix and claims only what is true of every `.penpot` row: it is Penpot's.
 * The vendor tree (`vnd.`) rather than `x-` because Penpot is a real vendor and
 * RFC 6838 deprecates `x-` for new types. Nothing is registered with IANA — a
 * search turned up no official mimetype for `.penpot` — so this is ours to pick,
 * and it is picked to be *narrow*, not to look official.
 *
 * ## THE ONE PLACE THIS DOES NOT COPY THE SIBLINGS
 *
 * Both siblings resolve their own directory as `\OC::$SERVERROOT .
 * '/custom_apps/' . APP_ID` — a hardcode that is right only when the app was
 * installed from the store. This repo's own integration workflow checks out into
 * `apps/penpot_sync`, where that path does not exist: the icon copy would miss,
 * warn, and leave every `.penpot` file with the generic glyph while every other
 * step reported success. So the path is resolved through {@see IAppManager},
 * which knows whichever apps directory the app actually lives in.
 */
final class RegisterMimetype implements IRepairStep {
	private const APP_MIMETYPE = 'application/vnd.penpot';
	private const APP_ALIAS_KEY = self::APP_MIMETYPE;
	private const APP_ICON_NAME = 'penpot';
	private const FILE_EXT = 'penpot';

	public function __construct(
		private IAppManager $appManager,
		private IMimeTypeDetector $detector,
		private IMimeTypeLoader $loader,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Register the penpot_sync mimetype + icon';
	}

	#[\Override]
	public function run(IOutput $output): void {
		$serverRoot = \OC::$SERVERROOT;
		// Custom config dir — Server::getServerRoot() . '/config' is the standard
		// location, but kubernetes mounts may place it elsewhere; resolve via OC.
		$configDir = \OC::$configDir;

		try {
			$this->mergeJson(
				$configDir . 'mimetypemapping.json',
				[self::FILE_EXT => [self::APP_MIMETYPE]],
			);
			$this->mergeJson(
				$configDir . 'mimetypealiases.json',
				[self::APP_ALIAS_KEY => self::APP_ICON_NAME],
			);
		} catch (\Throwable $e) {
			$this->logger->error('penpot_sync: failed to merge mimetype config', ['exception' => $e]);
			$output->warning('penpot_sync: could not update config/mimetype*.json (' . $e->getMessage() . ')');
		}

		// Copy the SVG into core/img/filetypes/. GenerateMimetypeFileBuilder scans
		// that directory verbatim, so the icon basename MUST match the alias value
		// written above ("penpot.svg" for alias "penpot").
		$this->installIcon($serverRoot, $output);

		// update-db: insert the mimetype row, then rewrite the filecache rows whose
		// extension matches. The detector cache is primed because we just touched
		// the on-disk config files.
		$this->detector->getAllMappings(); // primes lazy load (no public reset)
		$id = $this->loader->getId(self::APP_MIMETYPE);
		$touched = $this->loader->updateFilecache(self::FILE_EXT, $id);
		$output->info(sprintf(
			'penpot_sync: mimetype id=%d, %d filecache row(s) updated',
			$id,
			$touched,
		));

		// update-js: regenerate core/js/mimetypelist.js so the frontend map
		// includes our alias. Same code path as `occ maintenance:mimetype:update-js`.
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
	 * Copy `img/penpot.svg` into `core/img/filetypes/`, writing only when the
	 * bytes actually differ so an upgrade does not churn the core tree.
	 *
	 * Fail-soft on every branch: a missing icon costs the glyph, not the
	 * mimetype, and a repair step that threw here would abort an app upgrade over
	 * a decoration.
	 */
	private function installIcon(string $serverRoot, IOutput $output): void {
		try {
			$appRoot = $this->appManager->getAppPath(Application::APP_ID);
		} catch (AppPathNotFoundException $e) {
			$this->logger->error('penpot_sync: could not resolve the app path for the icon', ['exception' => $e]);
			$output->warning('penpot_sync: could not resolve the app directory; the filetype icon was not installed');

			return;
		}

		$src = $appRoot . '/img/' . self::APP_ICON_NAME . '.svg';
		if (!file_exists($src)) {
			$output->warning('penpot_sync: icon source missing at ' . $src);

			return;
		}

		$dst = $serverRoot . '/core/img/filetypes/' . self::APP_ICON_NAME . '.svg';
		$existing = is_file($dst) ? @file_get_contents($dst) : null;
		$incoming = @file_get_contents($src);
		if ($incoming === false || $existing === $incoming) {
			return;
		}
		if (@file_put_contents($dst, $incoming) === false) {
			$output->warning('penpot_sync: could not write ' . $dst);
		}
	}

	/**
	 * Read a JSON file (creating it if missing), merge `$additions` on top, and
	 * write it back. Atomic via tempfile + rename.
	 *
	 * @param array<string,mixed> $additions
	 */
	private function mergeJson(string $path, array $additions): void {
		$existing = [];
		if (is_file($path)) {
			$raw = file_get_contents($path);
			if ($raw !== false && trim($raw) !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$existing = $decoded;
				}
			}
		}
		$changed = false;
		foreach ($additions as $key => $value) {
			if (!array_key_exists($key, $existing) || $existing[$key] !== $value) {
				$existing[$key] = $value;
				$changed = true;
			}
		}
		if (!$changed && is_file($path)) {
			return;
		}
		$encoded = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
