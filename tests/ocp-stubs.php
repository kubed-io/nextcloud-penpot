<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Declaration-only stubs for the handful of OCP symbols the unit suite needs.
 *
 * `nextcloud/ocp` ships the interfaces but has no autoload block, so base
 * symbols don't resolve when running standalone. Rather than stub all of OCP,
 * this file covers **exactly what this slice's classes reference** and nothing
 * more — it should grow one entry at a time as real code lands, so it stays a
 * readable record of the app's actual surface rather than a dumping ground.
 *
 * Every declaration is guarded by an `*_exists(..., false)` check so that if the
 * real symbol is ever autoloadable (a full server tree, a future ocp release),
 * the real one wins and these become inert.
 */

namespace OCP\AppFramework {
	if (!class_exists(App::class, false)) {
		class App {
			public function __construct(string $appName, array $urlParams = []) {
			}
		}
	}
}

namespace OCP\AppFramework\Bootstrap {
	if (!interface_exists(IBootstrap::class, false)) {
		interface IBootstrap {
			public function register(IRegistrationContext $context): void;

			public function boot(IBootContext $context): void;
		}
	}
	if (!interface_exists(IRegistrationContext::class, false)) {
		interface IRegistrationContext {
		}
	}
	if (!interface_exists(IBootContext::class, false)) {
		interface IBootContext {
		}
	}
}

namespace OCP {
	// InstanceSettings and SetUrl read/write the base URL through AppConfig.
	// Declaration-only: both tests mock it.
	if (!interface_exists(IAppConfig::class, false)) {
		interface IAppConfig {
			public function getValueString(string $app, string $key, string $default = '', bool $lazy = false, bool $sensitive = false): string;

			public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool;

			// THE TYPED PAIR, which the schedule toggle round-trips through. Real
			// Nextcloud's getValueBool() THROWS AppConfigTypeConflict on a key that
			// was stored as a string rather than coercing it, and that behaviour is
			// the entire reason AppConfigReader exists — so a stub missing these
			// methods is not a smaller stub, it is one that cannot express the bug.
			public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool;

			public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool;

			// THE RETYPE PAIR. AppConfig will not change a key's type in place, so
			// turning the radio era's string `schedule_enabled` into a real bool means
			// removing the row and writing it again — which is what
			// {@see \OCA\PenpotSync\Migration\RetypeScheduleToggle} does, and why it
			// has to ask whether the key is there before deleting anything.
			public function hasKey(string $app, string $key, ?bool $lazy = false): bool;

			public function deleteKey(string $app, string $key): void;
		}
	}
	// PersonalTokenService stores per-USER values, which is a different config
	// interface from IAppConfig — user-scoped keys are the whole reason one
	// Nextcloud user's Penpot token cannot be read as another's.
	if (!interface_exists(IConfig::class, false)) {
		interface IConfig {
			public function getUserValue(string $userId, string $appName, string $key, string $default = ''): string;

			public function setUserValue(string $userId, string $appName, string $key, string $value, ?string $preCondition = null): void;

			public function deleteUserValue(string $userId, string $appName, string $key): void;
		}
	}
	// SetPersonalToken validates the target user exists, so a typo'd uid fails
	// loudly instead of storing a token nothing will ever read.
	if (!interface_exists(IUserManager::class, false)) {
		interface IUserManager {
			public function userExists(string $uid): bool;

			// {@see \OCA\PenpotSync\Service\TrashControl::listTrashed()} needs the
			// IUser itself, because `ITrashManager::listTrashRoot()` is scoped to one.
			// `$uid` is untyped to match core, which never added a type here.
			public function get($uid): ?IUser;
		}
	}
	// PersonalTokenService answers "whose token attributes this write?", so it
	// asks IUserSession who is acting. Declaration-only: PersonalTokenServiceTest
	// fakes a session with and without a signed-in user.
	if (!interface_exists(IUser::class, false)) {
		interface IUser {
			public function getUID(): string;
		}
	}
	if (!interface_exists(IUserSession::class, false)) {
		interface IUserSession {
			public function getUser(): ?IUser;
		}
	}
	// MappingSettings offers a per-mapping group picker, so it needs the list of
	// group ids. Declaration-only; only the settings panel touches it.
	if (!interface_exists(IGroup::class, false)) {
		interface IGroup {
			public function getGID(): string;
		}
	}
	if (!interface_exists(IGroupManager::class, false)) {
		interface IGroupManager {
			/** @return list<IGroup> */
			public function search(string $search): array;
		}
	}
	// AdminSection's constructor deps. Nothing asserts on them yet, but the class
	// must be loadable for a future AdminSection test.
	if (!interface_exists(IL10N::class, false)) {
		interface IL10N {
			public function t(string $text, array $parameters = []): string;
		}
	}
	if (!interface_exists(IURLGenerator::class, false)) {
		interface IURLGenerator {
			public function imagePath(string $appName, string $file): string;
		}
	}
}

namespace OCP\App {
	// MappingSettings asks whether groupfolders is installed, to decide whether
	// the Team Folder checkbox offers a backend that actually exists.
	if (!interface_exists(IAppManager::class, false)) {
		interface IAppManager {
			public function isEnabledForUser(string $appId): bool;
		}
	}
}

namespace OCP\Migration {
	// The repair-step pair, for {@see \OCA\PenpotSync\Migration\RetypeScheduleToggle}
	// — the one migration this app unit-tests, because it DELETES a user's setting
	// and a delete that half-lands is worse than the bug it repairs.
	//
	// Both are declaration-only and both match core's signatures exactly, untyped
	// returns included: `RetypeScheduleToggle::run()` narrows to `: void`, which is
	// legal against an untyped declaration and would NOT be against `: mixed`.
	if (!interface_exists(IOutput::class, false)) {
		interface IOutput {
			public function debug(string $message): void;

			public function info($message);

			public function warning($message);

			public function startProgress($max = 0);

			public function advance($step = 1, $description = '');

			public function finishProgress();
		}
	}
	if (!interface_exists(IRepairStep::class, false)) {
		interface IRepairStep {
			public function getName();

			public function run(IOutput $output);
		}
	}
}

namespace OCP\Security {
	// PenpotClient stores the service-account token encrypted at rest, and
	// SetToken writes it. Declaration-only: the unit suite mocks both directions
	// (a decrypt that throws is one of the states under test).
	if (!interface_exists(ICrypto::class, false)) {
		interface ICrypto {
			public function encrypt(string $input, string $password = ''): string;

			public function decrypt(string $input, string $password = ''): string;
		}
	}
}

namespace OCP\Http\Client {
	// PenpotClient issues every request through IClientService so that HTTP
	// proxying, `allow_local_address` and TLS settings stay consistent with the
	// rest of the platform. The unit suite never exercises the transport — that
	// is the integration suite's job against a real Penpot — so these exist only
	// so the class is constructible.
	if (!interface_exists(IResponse::class, false)) {
		interface IResponse {
			/**
			 * Upstream declares `string|resource`. `resource` is not a builtin
			 * type PHP accepts in a signature (it would be read as a class name),
			 * so the stub widens it to `mixed` and documents the real contract
			 * here. PenpotClient only ever casts the result to string.
			 *
			 * @return string|resource
			 */
			public function getBody(): mixed;

			public function getStatusCode(): int;

			public function getHeader(string $key): string;

			public function getHeaders(): array;
		}
	}
	if (!interface_exists(IClient::class, false)) {
		interface IClient {
			public function post(string $uri, array $options = []): IResponse;

			public function get(string $uri, array $options = []): IResponse;
		}
	}
	if (!interface_exists(IClientService::class, false)) {
		interface IClientService {
			public function newClient(): IClient;
		}
	}
	// Thrown when Nextcloud's own egress guard refuses a local address — common
	// in a homelab, where Penpot is reached in-cluster. PenpotClient catches it
	// specifically so the message can name `allow_local_remote_servers`.
	if (!class_exists(LocalServerException::class, false)) {
		class LocalServerException extends \Exception {
		}
	}
}

namespace OCP\Settings {
	// InstanceSettings implements IDeclarativeSettingsForm and reads
	// DeclarativeSettingsTypes constants. Declaration-only: the constant *values*
	// are irrelevant to the assertions (they check the schema's id/type/default),
	// so any stable strings suffice.
	if (!interface_exists(IDeclarativeSettingsForm::class, false)) {
		interface IDeclarativeSettingsForm {
			public function getSchema(): array;
		}
	}
	// AutoSyncSettings implements the WithHandlers variant, which is what lets it
	// own its own storage and dodge core's two typed-getter bugs (see that class).
	// Extending the base interface here mirrors upstream, so a test that only knows
	// about IDeclarativeSettingsForm still sees the form as one.
	if (!interface_exists(IDeclarativeSettingsFormWithHandlers::class, false)) {
		interface IDeclarativeSettingsFormWithHandlers extends IDeclarativeSettingsForm {
			public function getValue(string $fieldId, \OCP\IUser $user): mixed;

			public function setValue(string $fieldId, mixed $value, \OCP\IUser $user): void;
		}
	}
	if (!class_exists(DeclarativeSettingsTypes::class, false)) {
		final class DeclarativeSettingsTypes {
			public const SECTION_TYPE_ADMIN = 'admin';
			public const SECTION_TYPE_PERSONAL = 'personal';
			public const STORAGE_TYPE_INTERNAL = 'internal';
			public const STORAGE_TYPE_EXTERNAL = 'external';
			public const TEXT = 'text';
			public const URL = 'url';
			public const PASSWORD = 'password';
			public const CHECKBOX = 'checkbox';
			public const RADIO = 'radio';
		}
	}
	// AdminSection implements IIconSection (which extends ISection upstream —
	// flattened here since the split doesn't matter to the tests).
	if (!interface_exists(IIconSection::class, false)) {
		interface IIconSection {
			public function getID(): string;

			public function getName(): string;

			public function getPriority(): int;

			public function getIcon(): string;
		}
	}
}

namespace OCP\FilesMetadata {
	// PenpotMetadata wraps the Files-Metadata API. The PenpotMetadataTest drives
	// it through an in-memory fake of this manager to pin the link↔reference wire
	// and the file/folder key split. Declaration-only, just the methods
	// PenpotMetadata calls.
	if (!interface_exists(IFilesMetadataManager::class, false)) {
		interface IFilesMetadataManager {
			public function getMetadata(int $fileId, bool $generate = false): \OCP\FilesMetadata\Model\IFilesMetadata;

			public function saveMetadata(\OCP\FilesMetadata\Model\IFilesMetadata $filesMetadata): void;

			public function deleteMetadata(int $fileId): void;

			public function initMetadata(string $key, string $type, bool $indexed, int $editPermission): void;
		}
	}
}

namespace OCP\FilesMetadata\Model {
	if (!interface_exists(IFilesMetadata::class, false)) {
		interface IFilesMetadata {
			public function hasKey(string $needle): bool;

			public function getString(string $key): string;

			public function setString(string $key, string $value, bool $index = false): self;
		}
	}
	if (!interface_exists(IMetadataValueWrapper::class, false)) {
		interface IMetadataValueWrapper {
			public const TYPE_STRING = 'string';
			public const EDIT_FORBIDDEN = 0;
		}
	}
}

namespace OCP\FilesMetadata\Exceptions {
	if (!class_exists(FilesMetadataNotFoundException::class, false)) {
		class FilesMetadataNotFoundException extends \Exception {
		}
	}
}

namespace OCP\Files {
	// MembershipResolver walks UP the folder tree via Node::getParent(), reading
	// each ancestor's id. Declaration-only: the resolver test fakes a chain of
	// these. `getParent()` throws NotFoundException past the storage root.
	//
	// PullService additionally reads names/paths and moves nodes, so those live
	// on Node too (both File and Folder inherit them). Only the surface the app
	// actually calls is declared — a mock auto-implements exactly this.
	if (!interface_exists(Node::class, false)) {
		interface Node {
			public function getId(): int;

			public function getParent(): Node;

			public function getName(): string;

			public function getPath(): string;

			public function move(string $targetPath): Node;

			// The prune's only removal, and the reason it is not destructive: on a
			// user-visible node this is a move to the Nextcloud trash.
			public function delete(): void;

			/**
			 * The clocks {@see OCA\PenpotSync\Service\MirrorTimes} reads and writes.
			 * All four live on `FileInfo` upstream, which the real `Node` extends; this
			 * stub has no FileInfo, so they are declared here — the same place in the
			 * hierarchy. `touch(null)` means "now".
			 */
			public function getMTime(): int;

			public function getCreationTime(): int;

			public function getStorage(): \OCP\Files\Storage\IStorage;

			public function touch($mtime = null): void;
		}
	}
	// A folder the pull mirrors into: it lists children, checks/creates nodes,
	// and stamps folder metadata by id. PullServiceTest fakes these.
	if (!interface_exists(Folder::class, false)) {
		interface Folder extends Node {
			/** @return list<Node> */
			public function getDirectoryListing(): array;

			public function nodeExists(string $path): bool;

			public function get(string $path): Node;

			public function newFolder(string $path): Folder;

			public function newFile(string $path, mixed $content = null): File;

			/** @since NC 29. Resolve a node by id when only the id is known. */
			public function getFirstNodeById(int $id): ?Node;
		}
	}
	// A mirrored `.penpot` file. It is written whole (a link body), read back a
	// few bytes at a time (is this an archive?), and — since Course 4 — asked how
	// big it is before either, because opening an empty node to sniff four magic
	// bytes is work with a knowable answer.
	if (!interface_exists(File::class, false)) {
		interface File extends Node {
			public function putContent(mixed $data): void;

			public function getSize(): int|float;

			/**
			 * Upstream returns `resource|false`. `resource` is not a type PHP accepts
			 * in a signature, so the stub widens it — ArchiveService treats anything
			 * that is not a usable stream as "no archive".
			 *
			 * @return resource|false
			 */
			public function fopen(string $mode): mixed;
		}
	}
	// The sync actor's home is reached through the root folder — the entry point
	// every `occ` command uses to turn a path argument into a node.
	if (!interface_exists(IRootFolder::class, false)) {
		interface IRootFolder {
			public function getUserFolder(string $userId): Folder;
		}
	}
	// Thrown by Node::getParent() once the walk runs past the root — the
	// resolver catches it to terminate the climb.
	if (!class_exists(NotFoundException::class, false)) {
		class NotFoundException extends \Exception {
		}
	}
}

namespace OCP\Exceptions {
	// MoveGuardListener throws this to REFUSE a move before it happens (§6.30) —
	// the ONE exception `OC_Hook::emit()` does not swallow on the way through,
	// because `HookConnector` catches it by name. The message it carries does NOT
	// survive that catch; {@see OCA\PenpotSync\DAV\LinkWriteGuardPlugin} is what
	// says why. Here it only needs to exist so the guard's test can assert the
	// refusal.
	if (!class_exists(AbortedEventException::class, false)) {
		class AbortedEventException extends \Exception {
		}
	}
}

namespace OCP\EventDispatcher {
	// The event base and the listener contract. Both listeners implement
	// IEventListener and type-check the concrete event, so both symbols have to
	// resolve before the classes can even be loaded.
	if (!class_exists(Event::class, false)) {
		class Event {
		}
	}
	if (!interface_exists(IEventListener::class, false)) {
		interface IEventListener {
			public function handle(Event $event): void;
		}
	}
}

namespace OCP\Files\Events\Node {
	// The move/rename events. Nextcloud fires ONE event class for both gestures;
	// the `Before` variant is the only place a move can still be refused.
	// Non-final and concrete so the guard's test can mock the accessors.
	if (!class_exists(BeforeNodeRenamedEvent::class, false)) {
		class BeforeNodeRenamedEvent extends \OCP\EventDispatcher\Event {
			public function getSource(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}

			public function getTarget(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
	if (!class_exists(NodeRenamedEvent::class, false)) {
		class NodeRenamedEvent extends \OCP\EventDispatcher\Event {
			public function getSource(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}

			public function getTarget(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
	// The delete event, fired for BOTH steps of a delete — the first one on the
	// way to the trash, and the purge out of it. It carries a single node, and
	// which step it is has to be read off that node's PATH, which is why the
	// listener's test mocks this rather than trusting the service tests.
	if (!class_exists(BeforeNodeDeletedEvent::class, false)) {
		class BeforeNodeDeletedEvent extends \OCP\EventDispatcher\Event {
			public function getNode(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
	// A file being written — a create, an upload, or a mirror the pull just
	// refreshed. Same shape.
	if (!class_exists(NodeWrittenEvent::class, false)) {
		class NodeWrittenEvent extends \OCP\EventDispatcher\Event {
			public function getNode(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
	// A copy. Source and target, like the rename events.
	if (!class_exists(NodeCopiedEvent::class, false)) {
		class NodeCopiedEvent extends \OCP\EventDispatcher\Event {
			public function getSource(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}

			public function getTarget(): \OCP\Files\Node {
				throw new \LogicException('stub');
			}
		}
	}
}

namespace OCP\Files\Storage {
	// The two hops {@see OCA\PenpotSync\Service\MirrorTimes} takes to set a creation
	// time — there is no OCP setter for it, so the public cache API is the supported
	// route (Node::getStorage -> IStorage::getCache -> ICache::update).
	if (!interface_exists(IStorage::class, false)) {
		interface IStorage {
			public function getCache(string $path = '', ?IStorage $storage = null): \OCP\Files\Cache\ICache;
		}
	}
}

namespace OCP\Files\Cache {
	if (!interface_exists(ICache::class, false)) {
		interface ICache {
			public function update($id, array $data);
		}
	}
}
