# Renaming a PROJECT — the folder in Nextcloud and the Penpot project it maps to.
# Renaming a DESIGN is rename-design.feature: same gesture in the Files app, but
# a different Penpot object (`rename-project` vs `rename-file`) and a different
# set of name rules, because a project name has to survive becoming a folder name.
#
# A PROJECT IS A FOLDER. That is why this file exists separately: in Nextcloud a
# Penpot project has no representation other than a folder, so every constraint
# Nextcloud puts on folder names lands here and nowhere else.
#
# PENPOT → NEXTCLOUD: the pull compares Penpot's project name against the folder
# on disk and renames the folder, keyed on `penpot_project_id` — never on the
# name, which is exactly what a rename would defeat.
#
# NEXTCLOUD → PENPOT: locked since saga §6.36. This direction was settled BEFORE
# the file rename was (§6.54), and the asymmetry it created is what forced that
# decision.
#
# THE NAME RULES ARE THE SUBSTANCE HERE. A project name that cannot become a
# folder name is REFUSED rather than sanitised (saga §6.51): "foo/bar" and
# "foo-bar" would both collapse to "foo-bar", silently merging two distinct
# projects into one folder with no way to tell which is which. Refusing visibly
# beats breaking the names-always-match rule invisibly. Inferring a parent folder
# from the "/" is `keyed` mode — a deliberate per-mapping choice (§6.53), never a
# fallback triggered by one awkward name.

Feature: Renaming a Penpot project
  As a Nextcloud user
  I want renaming a project folder to rename the project in Penpot, and vice versa
  So that the folder tree and the Penpot team always read the same
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And no Penpot teams are mapped
    And the first visible team is mapped as a plain folder "Penpot"

  # A PROJECT FOLDER IS ITS OWN FLOW, not a variant of the file rename (§6.36 /
  # §6.39): a different event, a different id, a different RPC, and a 204 with no
  # body instead of a record. It had no live coverage at all, which meant the two
  # rename paths were one green test and one assumption.
  #
  # The assertion works because `penpot_sync:probe` lists PENPOT's own project
  # names — so finding a design under the new name proves Penpot renamed the
  # project, not merely that Nextcloud renamed a folder.
  @in-nextcloud @gesture
  Scenario: Renaming a project folder renames the project in Penpot
    Given a mirrored design "Inside" in the project "Old Project Name"
    When I rename "Penpot/Old Project Name" to "New Project Name"
    Then Penpot project "New Project Name" holds a design named "Inside"
    And the folder "Penpot/New Project Name" carries a Penpot project id

  @in-nextcloud @gesture
  Scenario: Renaming a project folder does not touch the designs inside it
    Given a mirrored design "Untouched Design" in the project "Renamed Around It"
    When I rename "Penpot/Renamed Around It" to "Renamed Around It v2"
    Then Penpot project "Renamed Around It v2" holds a design named "Untouched Design"
    And the file "Penpot/Renamed Around It v2/Untouched Design.penpot" carries a Penpot id
    # `rename-project` takes the PROJECT id; nothing about the files changes, and
    # a regression that sent file ids here would rename a design instead — which
    # this catches, because the design would no longer be found by its own name.

  @in-nextcloud @gesture @todo
  Scenario: A failed project rename leaves the local rename standing
    Given a mirrored project "My Stuff"
    When I rename the project folder and the Penpot call fails
    Then the folder keeps its new name locally
    And Penpot is unchanged
    And the divergence is reported
    And the next pull reconciles the name
    # Saga §6.18 rule 3 — a remote failure never destroys local state. Same rule
    # as the file twin below, and it has to be stated for both because they are
    # different listeners reading different ids.

  @in-nextcloud @gesture @blocked
  Scenario: A project rename is attributed to the acting user
    Given the user has a valid personal Penpot token
    When the user renames a project folder
    Then "rename-project" is called using that user's own token
    # Needs a logged-in session the occ+DAV harness does not have.

    # ── Penpot → Nextcloud: confirmed, this is how renames normally happen ───────

  @in-penpot @todo
  Scenario: Renaming a project in Penpot renames the folder on the next pull
    Given a mirrored project "My Stuff"
    When the project is renamed to "Acme" in Penpot
    And the team is mirrored again
    Then the folder is renamed to "Acme"
    And the folder stays exactly where the user had put it
    # Nextcloud is authoritative for LAYOUT (§6.29), so the pull renames in place
    # and never drags the folder back to a canonical path.

  @todo
  Scenario: An empty or whitespace-only folder name is refused
    When I try to rename a project folder to a name that is empty once trimmed
    Then the rename is refused with an explanation
    And Penpot is never contacted
    # The one rule Penpot actually enforces: [:string {:max 250, :min 1}].

  @todo
  Scenario: A folder name longer than Penpot allows is refused before it is sent
    When I try to rename a project folder to a name longer than 250 characters
    Then the rename is refused with an explanation naming the limit
    And Penpot is never contacted

  @todo
  Scenario: In nested mode the app never sends a slash to Penpot
    Given the mapping's folder mode is "nested"
    When a project is created or renamed through this app
    Then the resulting Penpot project name never contains "/"
    # A Nextcloud folder name cannot contain "/" anyway, so this is automatic for
    # renames — but it must also hold for the CREATE path (create-project.feature's
    # tag opt-in), which is where a name could be composed rather than typed.

  @in-penpot @todo
  Scenario: In nested mode, a project whose name contains a slash is skipped with a clear reason
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder is created for that project
    And no files from that project are mirrored
    And the admin is told the project cannot be mirrored because "/" is not allowed in a folder name
    And the message names the project so it can be renamed in Penpot

  @in-penpot @todo
  Scenario: One unmappable project does not block the rest of the team
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    And other projects with ordinary names in the same team
    When the pull runs
    Then every other project is mirrored normally
    And only the unmappable project is skipped

  @in-penpot @todo
  Scenario: Renaming the project in Penpot fixes it on the next pull
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash" that was skipped
    When it is renamed to "Has Slash" in Penpot
    And the pull runs
    Then a folder named "Has Slash" is created
    And its files are mirrored normally

  @in-penpot @todo
  Scenario: The app never invents a substitute name
    Given the mapping's folder mode is "nested"
    And a Penpot project named "Has/Slash"
    When the pull runs
    Then no folder named "Has-Slash" or "Has Slash" is created for it
    # Sanitising is REJECTED (saga §6.51): "foo/bar" and "foo-bar" would both
    # become "foo-bar", silently collapsing two distinct projects into one folder
    # with no way to tell which is which. That breaks the names-always-match rule
    # invisibly, which is worse than refusing visibly. Inferring a parent folder
    # from the "/" is `keyed` mode — a deliberate per-mapping choice (§6.53), not
    # something to fall back into because one name happened to contain a slash.

    # ── the invariant, true under either branch ─────────────────────────────────
