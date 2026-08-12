# Notes, decisions and history for this feature: ../AGENTS.md#designsrename

Feature: Renaming a design
  As a Nextcloud user
  I want a rename made on either side to reach the other
  So that one name means one thing in both places

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |
    And a mapping with the following values:
      | team   | Reference Team |
      | folder | Pointers       |
      | mode   | link           |

    # ── RULE: a name is one value living in two places ────────────────────────
    # notes: ../AGENTS.md#renaming-a-mirrored-file-renames-its-design-in-penpot

  @in-nextcloud @gesture
  Scenario: Rename a design in Nextcloud
    Given a mirrored design "Old Name" in the project "Rename Live"
    When I rename "Penpot/Rename Live/Old Name.penpot" to "New Name.penpot"
    Then Penpot project "Rename Live" holds a design named "New Name"
    And Penpot project "Rename Live" holds no design named "Old Name"
    And "Penpot/Rename Live/New Name.penpot" holds:
      | penpot_id   | the id it had before the rename |
      | penpot_mode | "sync"                          |
      | content     | an archive                      |

    # The id is the whole anti-break claim: a rename must move the design, never
    # replace it with a new one wearing the new name.

  # notes: ../AGENTS.md#a-rename-is-picked-up-in-both-modes-without-an-export
  @in-penpot @gesture @todo
  Scenario Outline: Rename a design in Penpot
    Given a mirrored design "Old Name" in the project "Renamed <mode>"
    When someone renames the design to "New Name" in Penpot
    Then "<folder>/Renamed <mode>/New Name.penpot" holds:
      | penpot_id   | the id it had before the rename |
      | penpot_mode | "<mode>"                        |
    And "<folder>/Renamed <mode>" holds no file named "Old Name.penpot"

    Examples: a link holds no archive, but its NAME still mirrors
      | folder   | mode |
      | Penpot   | sync |
      | Pointers | link |

    # ── RULE: a name Penpot cannot hold is refused before it is sent ──────────
    # notes: ../AGENTS.md#an-empty-file-name-is-refused-before-it-is-sent

  @in-nextcloud @gesture @todo
  Scenario Outline: Rename a design to a name Penpot cannot hold
    Given a mirrored design "Old Name" in the project "Refusals"
    When I try to rename it to <name>
    Then the rename is refused, explaining what is wrong with the name
    And Penpot project "Refusals" holds a design named "Old Name"

    Examples: two names, one refusal — neither ever reaches Penpot
      | name                                       |
      | a name that is empty once ".penpot" is off |
      | a name longer than Penpot allows           |

    # ── RULE: a rename Penpot will not take still stands locally ──────────────
    # notes: ../AGENTS.md#a-failed-propagation-never-reverts-the-users-local-rename

  @in-nextcloud @gesture @todo
  Scenario: Rename a design while Penpot is unreachable
    Given a mirrored design "Old Name" in the project "Offline"
    And Penpot is unreachable
    When I rename "Penpot/Offline/Old Name.penpot" to "New Name.penpot"
    Then the file "Penpot/Offline/New Name.penpot" carries a Penpot id
    And the failure is reported to the user

    # Nextcloud has already renamed it, and reverting would fight the user over a
    # gesture that succeeded locally.

    # ── RULE: a rename is attributed to whoever made it ───────────────────────
    # notes: ../AGENTS.md#a-propagated-rename-is-attributed-to-the-acting-user

  @in-nextcloud @gesture @blocked
  Scenario Outline: Rename a design as a user with or without a personal token
    Given the user <token>
    And a mirrored design "Old Name" in the project "Attribution"
    When I rename "Penpot/Attribution/Old Name.penpot" to "New Name.penpot"
    Then Penpot attributes the rename to <actor>

    Examples: whose change it looks like in Penpot's own history
      | token                             | actor               |
      | has a personal Penpot token       | that user           |
      | has no personal Penpot token      | the service account |

    # ── RULE: a file the app does not manage is Nextcloud's alone ─────────────
    # notes: ../AGENTS.md#renaming-an-untracked-penpot-file-is-not-a-failure

  @in-nextcloud @gesture
  Scenario: Rename an untracked ".penpot" file
    Given a mirrored project "Untracked Rename"
    And an untracked ".penpot" archive at "Penpot/Untracked Rename/Dragged In.penpot"
    When I rename "Penpot/Untracked Rename/Dragged In.penpot" to "Renamed Anyway.penpot"
    Then the file "Penpot/Untracked Rename/Renamed Anyway.penpot" carries no Penpot id
    And Penpot project "Untracked Rename" holds no design named "Renamed Anyway"
