# Notes, decisions and history for this feature: ../AGENTS.md#designscopy

Feature: Copying a design
  As a Nextcloud user
  I want a copy to be a new design, never a hijack of the original
  So that copying a file is safe and predictable

  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a mapping with the following values:
      | team    | Design Team  |
      | folder  | Penpot       |
      | mode    | sync         |
      | storage | admin folder |
    And a mapping with the following values:
      | team    | Reference Team |
      | folder  | Pointers       |
      | mode    | link           |
      | storage | admin folder   |
    And a mapping with the following values:
      | team    | Second Team |
      | folder  | Shared      |
      | mode    | sync        |
      | storage | team folder |
      | groups  | admin       |
    And a folder at "Scratch" that is not mapped

    # ── RULE: the copy belongs to where it lands, never to where it came from ─
    # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands

  @in-nextcloud @gesture @unbuilt
  Scenario Outline: Copy a design into a mapped project
    Given a design file named "Original.penpot" in "<source>"
    When I copy the file into "<destination>"
    Then the copy holds:
      | filename        | "<copy>"           |
      | name in Penpot  | "<named>"          |
      | penpot_id       | a new id           |
      | penpot_mode     | the mapping's mode |
      | penpot_team_id  | the mapping's team |
      | penpot_revision | set                |
    And the copy is a new design in the Penpot project "<lands in>"
    And the original file and its design are unchanged

    Examples: Nextcloud names the copy, and that name is the design's name
      | source           | destination      | copy                | named        | lands in  |
      | Penpot/Copy Here | Penpot/Copy Here | Original (1).penpot | Original (1) | Copy Here |
      | Scratch          | Penpot/Copy Here | Original.penpot     | Original     | Copy Here |
      | Penpot/Copy Here | Penpot/Elsewhere | Original.penpot     | Original     | Elsewhere |

  # notes: ../AGENTS.md#copying-to-the-team-root-creates-the-design-in-drafts
  # notes: ../AGENTS.md#copying-a-design-across-two-mappings-makes-a-new-design-in-the-destination-team

    Examples: the team root means Drafts, and another team means another storage kind
      | source           | destination     | copy            | named    | lands in |
      | Penpot/Copy Here | Penpot          | Original.penpot | Original | Drafts   |
      | Penpot/Copy Here | Shared/Handover | Original.penpot | Original | Handover |

  # notes: ../AGENTS.md#exactly-one-file-per-design-id-under-a-project-always
  @in-nextcloud @gesture @unbuilt
  Scenario: Copying a design twice makes two designs, not two claims on one
    Given a design file named "Original.penpot" in "Penpot/Twice"
    When I copy the file into "Penpot/Twice"
    And I copy the file into "Penpot/Twice"
    Then "Penpot/Twice" holds one file per design, named:
      | Original.penpot     |
      | Original (1).penpot |
      | Original (2).penpot |
    And all three files carry different Penpot ids

    # ── RULE: a link copies like anything else, but not into a link mapping ───
    # notes: ../AGENTS.md#a-link-file-copies-exactly-like-a-sync-file

  @in-nextcloud @gesture @unbuilt
  Scenario: Copy a link design into a sync mapping
    Given a design file named "Pointer.penpot" in "Pointers/Confined"
    When I copy the file into "Penpot/Landing"
    Then the copy holds:
      | filename    | "Pointer.penpot"   |
      | penpot_id   | a new id           |
      | penpot_mode | the mapping's mode |
    And the copy is a new design in the Penpot project "Landing"
    And the original file and its design are unchanged

    # Penpot diverges from both siblings here: `duplicate-file` copies server-side
    # from the id, so a zero-byte pointer duplicates as completely as an archive.

  @in-nextcloud @gesture @unbuilt
  Scenario Outline: Copying into a link mapping is refused
    Given a design file named "Original.penpot" in "<source>"
    When I try to copy the file into "<destination>"
    Then the copy is refused with a message
    And no file is added to "<destination>"
    And no design is created in Penpot for the copy
    And the original file and its design are unchanged

    Examples: a link mapping is filled from Penpot, whatever is arriving
      | source            | destination       |
      | Penpot/Copy Here  | Pointers/Confined |
      | Pointers/Confined | Pointers/Confined |
      | Penpot/Copy Here  | Pointers          |

    # ── RULE: a copy landing outside every mapping creates nothing in Penpot ──
    # notes: ../AGENTS.md#copying-a-penpot-file-outside-every-mapping-never-contacts-penpot
    # notes: ../AGENTS.md#copying-outside-every-mapping-creates-nothing-in-penpot

  @in-nextcloud @gesture @unbuilt
  Scenario Outline: Copy a design into an unmapped folder
    Given a design file named "Original.penpot" in "<source>"
    When I copy the file into "Scratch"
    Then no design is created in Penpot for the copy
    And the copy carries <identity>
    And the copy's body is byte-for-byte the original's
    And the original file and its design are unchanged

  # notes: ../AGENTS.md#a-sync-copy-keeps-its-archive-and-is-a-valid-file-on-its-own

    Examples: there is nowhere to create, and the id it kept is a record of origin
      | source           | identity                          |
      | Penpot/Copy Out  | the id of the design it came from |
      | Scratch          | no Penpot id                      |

    # ── RULE: a copy is usable the instant it exists ──────────────────────────
    # notes: ../AGENTS.md#a-copy-is-tracked-the-moment-it-exists-so-the-next-action-works
    # notes: ../AGENTS.md#a-copy-can-be-renamed-immediately-because-it-was-tracked

  @in-nextcloud @gesture @unbuilt
  Scenario: Rename a copy straight after making it
    Given a design file named "Original.penpot" in "Penpot/Fresh"
    And I copy the file into "Penpot/Fresh"
    When I rename "Penpot/Fresh/Original (1).penpot" to "Second Draft.penpot"
    Then Penpot project "Fresh" holds a design named "Second Draft"
    And the original file and its design are unchanged

    # A copy that failed to record its id presents as a broken RENAME one gesture
    # later, which is how this reached a human before it reached a test.

    # ── RULE: a copy Penpot will not take stays a plain file ──────────────────
    # notes: ../AGENTS.md#a-copy-that-cannot-be-tracked-says-so-rather-than-looking-finished
    # notes: ../AGENTS.md#a-failed-duplicate-leaves-the-nextcloud-copy-standing

  @in-nextcloud @gesture @todo
  Scenario: Copy a design while Penpot is unreachable
    Given a design file named "Original.penpot" in "Penpot/Offline"
    And Penpot is unreachable
    When I copy the file into "Penpot/Offline"
    Then the file "Penpot/Offline/Original (1).penpot" carries no Penpot id
    And the failure is reported to the user
    And the original file and its design are unchanged

    # Carrying the original's id would be the worst outcome: two files claiming one
    # design, which is the ambiguity this whole feature exists to avoid.

  @in-nextcloud @gesture @todo
  Scenario: Copy a design whose name is already as long as Penpot allows
    Given a mirrored design named as long as Penpot allows, in the project "Long Names"
    When I copy it within "Penpot/Long Names"
    Then the design in Penpot is named by the copy's filename, truncated to fit
    And the file keeps the whole name Nextcloud gave it

    # Nextcloud picks the copy's name, so a design already at the limit produces one
    # over it. Truncating beats refusing a gesture that already succeeded locally.

    # ── RULE: a duplicate made in Penpot is just a new design ─────────────────
    # notes: ../AGENTS.md#a-design-duplicated-in-penpot-is-mirrored-like-any-other-new-design

  @in-penpot @gesture @todo
  Scenario: Duplicate a design in Penpot
    Given a design file named "Original.penpot" in "Penpot/Duplicated"
    When someone duplicates its design in Penpot
    Then "Penpot/Duplicated" holds one file per design
    And those two files carry different Penpot ids
    And each file holds the content of the design it stands for

    # Penpot never says a design was duplicated — it arrives with a fresh id and a
    # name of its own, so the reconciler mirrors it the way it mirrors anything new.

  # notes: ../AGENTS.md#a-duplicate-made-in-penpot-inherits-the-mappings-mode-not-the-originals
  @in-penpot @gesture @todo
  Scenario Outline: A duplicate made in Penpot takes the mapping's mode
    Given a design file named "Original.penpot" in "<folder>/Inherited"
    When someone duplicates its design in Penpot
    Then the arriving file holds:
      | penpot_mode | "<mode>" |

    Examples: where it lands decides what it is, not what it was copied from
      | folder   | mode      |
      | Penpot   | sync      |
      | Pointers | reference |
