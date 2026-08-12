# Notes, decisions and history for this feature: ../AGENTS.md#designscopy

Feature: Copying a design
  As a Nextcloud user
  I want a copied design file to become a real new design in Penpot
  So that duplicating work in Files duplicates it where the work actually lives

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team   | Design Team |
      | folder | Penpot      |
      | mode   | sync        |

    # ── RULE: the copy belongs to where it lands, never to where it came from ─
    # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands

  @in-nextcloud @gesture
  Scenario Outline: Copy a design inside a mapping
    Given a mirrored design "Original" in the project "Copy Here"
    And a mirrored project "Elsewhere"
    When I copy "Penpot/Copy Here/Original.penpot" to "<destination>/Original copy.penpot"
    Then Penpot project "<lands in>" holds a design named "Original copy"
    And the files "Penpot/Copy Here/Original.penpot" and "<destination>/Original copy.penpot" carry different Penpot ids
    And Penpot project "Copy Here" holds a design named "Original"

    Examples: the destination decides the project, and the team root means Drafts
      | destination      | lands in  |
      | Penpot/Copy Here | Copy Here |
      | Penpot/Elsewhere | Elsewhere |
      | Penpot           | Drafts    |

    # Different ids is the load-bearing claim: two files claiming one design is the
    # ambiguity the whole feature exists to avoid.

    # ── RULE: a copy outside every mapping is a plain file ────────────────────
    # notes: ../AGENTS.md#copying-a-penpot-file-outside-every-mapping-never-contacts-penpot

  @in-nextcloud @gesture @unbuilt
  Scenario: Copy a design out of every mapping
    Given a mirrored design "Original" in the project "Copy Out"
    When I copy "Penpot/Copy Out/Original.penpot" to "Loose Copy.penpot"
    Then the file "Loose Copy.penpot" carries no Penpot id
    And Penpot project "Copy Out" holds no design named "Loose Copy"
    And Penpot project "Copy Out" holds a design named "Original"

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: the copy
    # keeps the original's id, so two files claim one design. Both sibling apps
    # strip the identity from a copy landing outside every mapping.

  @in-nextcloud @gesture
  Scenario: Copy a file the app does not manage
    Given a mirrored project "Bystanders"
    And an untracked ".penpot" archive at "Loose Design.penpot"
    When I copy "Loose Design.penpot" to "Loose Design copy.penpot"
    Then the file "Loose Design copy.penpot" carries no Penpot id
    And Penpot project "Bystanders" holds no design named "Loose Design copy"

    # ── RULE: the copy is named after the file, and the name must fit ─────────

  @in-nextcloud @gesture @todo
  Scenario: Copy a design whose name is already as long as Penpot allows
    Given a mirrored design named as long as Penpot allows, in the project "Long Names"
    When I copy it within "Penpot/Long Names"
    Then the design in Penpot is named by the copy's filename, truncated to fit
    And the file keeps the whole name Nextcloud gave it

    # Nextcloud picks the copy's name, so a design already at the limit produces one
    # over it. Truncating beats refusing a gesture that already succeeded locally.

    # ── RULE: a copy Penpot will not take stays a plain file ──────────────────
    # notes: ../AGENTS.md#a-copy-that-cannot-be-tracked-says-so-rather-than-looking-finished

  @in-nextcloud @gesture @todo
  Scenario: Copy a design while Penpot is unreachable
    Given a mirrored design "Original" in the project "Offline"
    And Penpot is unreachable
    When I copy "Penpot/Offline/Original.penpot" to "Penpot/Offline/Original copy.penpot"
    Then the file "Penpot/Offline/Original copy.penpot" carries no Penpot id
    And the failure is reported to the user
    And Penpot project "Offline" holds a design named "Original"

    # ── RULE: two near-identical designs are still two designs ────────────────
    # notes: ../AGENTS.md#a-duplicate-made-in-penpot-is-two-designs-not-one

  @in-penpot @gesture @todo
  Scenario: Duplicate a design in Penpot
    Given a mirrored design "Original" in the project "Duplicated"
    When someone duplicates the design in Penpot
    Then "Penpot/Duplicated" holds a file for each of the two designs
    And those two files carry different Penpot ids
    And each file holds the content of the design it stands for
