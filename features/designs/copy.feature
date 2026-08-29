# Notes, decisions and history for this feature: ../AGENTS.md#designscopy

Feature: Copying a design
  As a Nextcloud user
  I want a copy to be a new design, never a hijack of the original
  So that copying a file is safe and predictable

  Background:
    Given the app is connected to Penpot
    And the following mappings were made:
      | team           | folder   | mode | storage      | groups |
      | Design Team    | Penpot   | sync | admin folder |        |
      | Second Team    | Shared   | sync | team folder  | admin  |
      | Reference Team | Pointers | link | admin folder |        |
    And a folder at "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the copy belongs to where it lands, never to where it came from ─
    # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands

  @in-nextcloud @gesture
  Scenario Outline: Copy a design into a mapped project
    Given a design file named "Original.penpot" in "<source>"
    When I copy the file into "<destination>"
    Then the copy holds:
      | filename        | "<copy>"                             |
      | name in Penpot  | "<named>"                            |
      | penpot_id       | a new id                             |
      | penpot_mode     | the mapping's mode                   |
      | penpot_team_id  | the mapping's team                   |
      | penpot_revision | set                                  |
      | Created         | when the design was created in Penpot |
    And the copy is a new design in the Penpot project "<lands in>"
    And the original file and its design are unchanged

    # Two places, not the siblings' three: a `.penpot` body is a binary archive, so
    # there is no name inside it to disagree with the filename.

  # notes: ../AGENTS.md#a-copys-clocks-are-its-own

    Examples: Nextcloud names the copy, and that is its name everywhere
      | source           | destination      | copy                | named        | lands in  |
      | Penpot/Copy Here | Penpot/Copy Here | Original (2).penpot | Original (2) | Copy Here |
      | Scratch          | Penpot/Copy Here | Original.penpot     | Original     | Copy Here |
      | Penpot/Copy Here | Shared/Handover  | Original.penpot     | Original     | Handover  |

  # notes: ../AGENTS.md#a-copy-made-in-nextcloud-is-named-by-nextcloud
  # notes: ../AGENTS.md#copying-to-the-team-root-creates-the-design-in-drafts

    Examples: where it lands is the folder it lands IN, and the team root is Drafts
      | source           | destination          | copy            | named    | lands in      |
      | Penpot/Copy Here | Penpot/Copy Here/wip | Original.penpot | Original | Copy Here/wip |
      | Penpot/Copy Here | Penpot               | Original.penpot | Original | Drafts        |
      | Penpot/Copy Here | Shared               | Original.penpot | Original | Drafts        |

    # ── RULE: a design duplicated in Penpot arrives as its own file ───────────
    # notes: ../AGENTS.md#a-design-duplicated-in-penpot-is-mirrored-like-any-other-new-design

  @in-penpot @gesture
  Scenario Outline: Duplicate a design in Penpot
    Given a design file named "Original.penpot" in "<folder>/Duplicated"
    When someone duplicates its design in Penpot
    Then the copy arrives as its own file in "<folder>/Duplicated"
    And that file holds:
      | filename       | "Original (copy).penpot" |
      | name in Penpot | "Original (copy)"        |
      | penpot_id      | a new id                 |
      | penpot_mode    | "<mode>"                 |
    And the original file and its design are unchanged

    Examples: the mapping it lands in decides the mode, in either storage kind
      | folder   | mode      |
      | Penpot   | sync      |
      | Shared   | sync      |
      | Pointers | reference |

    # ── RULE: a link is not copyable, and a link mapping is not a destination ─
    # notes: ../AGENTS.md#a-link-file-copies-exactly-like-a-sync-file

  @in-nextcloud @gesture
  Scenario Outline: Copying a link, or into a link mapping, is refused
    Given a design file named "Original.penpot" in "<source>"
    When I try to copy the file into "<destination>"
    Then the copy is refused with a message
    And no file is added to "<destination>"
    And no design is created in Penpot for the copy
    And the original file and its design are unchanged

    Examples: a link is read-only in Nextcloud, and there is nowhere it may go
      | source   | destination |
      | Pointers | Penpot      |
      | Pointers | Scratch     |
      | Pointers | Pointers    |

    Examples: and a link mapping is filled from Penpot, whatever is arriving
      | source | destination |
      | Penpot | Pointers    |

    # ── RULE: a copy landing outside every mapping is a plain file ────────────
    # notes: ../AGENTS.md#copying-a-penpot-file-outside-every-mapping-never-contacts-penpot
    # notes: ../AGENTS.md#copying-outside-every-mapping-creates-nothing-in-penpot

  @in-nextcloud @gesture
  Scenario Outline: Copy a design into an unmapped folder
    Given a design file named "Original.penpot" in "<source>"
    When I copy the file into "Scratch"
    Then no design is created in Penpot for the copy
    And the copy's body is byte-for-byte the original's
    And the original file and its design are unchanged

  # notes: ../AGENTS.md#a-sync-copy-keeps-its-archive-and-is-a-valid-file-on-its-own

    Examples: from either storage kind, and from no mapping at all
      | source          |
      | Penpot/Copy Out |
      | Shared/Handover |
      | Scratch         |

  # notes: ../AGENTS.md#exactly-one-file-per-design-id-under-a-project-always
  # notes: ../AGENTS.md#nextclouds-collision-suffix-starts-at-2
  @in-penpot @gesture
  Scenario: Three designs in Penpot wearing one name
    Given a design file named "Original.penpot" in "Penpot/Crowded"
    When someone duplicates its design in Penpot and names it "Original"
    And someone duplicates its design in Penpot and names it "Original"
    Then "Penpot/Crowded" holds one file per design, named:
      | Original.penpot     |
      | Original (2).penpot |
      | Original (3).penpot |
    And all three designs are still named "Original" in Penpot

    # ── RULE: a copy Penpot will not take stays a plain file ──────────────────
    # notes: ../AGENTS.md#a-copy-that-cannot-be-tracked-says-so-rather-than-looking-finished
    # notes: ../AGENTS.md#a-failed-duplicate-leaves-the-nextcloud-copy-standing

  # notes: ../AGENTS.md#there-is-nowhere-for-a-failure-to-be-reported-to
  # @unbuilt — the untracked copy is right already; the report is what is missing.
  @in-nextcloud @gesture @unbuilt
  Scenario: Copy a design while Penpot is unreachable
    Given a design file named "Original.penpot" in "Penpot/Offline"
    And Penpot is unreachable
    When I copy the file into "Penpot/Offline"
    Then the file "Penpot/Offline/Original (1).penpot" carries no Penpot id
    And the failure is reported to the user
    And the original file and its design are unchanged

    # Carrying the original's id would be the worst outcome: two files claiming one
    # design, which is the ambiguity this whole feature exists to avoid.
