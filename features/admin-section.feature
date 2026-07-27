# The SHAPE of the admin section, and the actions in it.
#
# WHY THIS FILE EXISTS AT ALL. Every other feature file specifies what the app
# DOES; this one specifies what the admin SEES, because in this app family that
# is a deliberate, load-bearing decision rather than an incidental one.
#
# THE FAMILY LAYOUT (settled on the n8n master, inherited by nextcloud-grafana,
# and matched here). All three apps present the same four panels in the same
# order, so an admin who has configured one already knows where to look:
#
#   Instance (5)       where it is + how we authenticate — ONE card
#   Sync Settings (20) how syncing behaves automatically
#   Team mappings (30) the repeating list — one card per mapping
#   Sync Actions (45)  EVERY button in the section, rendered last
#
# The last rule is the one most easily lost. Nextcloud's declarative settings
# cannot host buttons, and giving each button its own panel beside its data card
# turns the section into a stack of thin strips. So: one classic panel for every
# button, at the bottom, beneath the data it acts on.
#
# WHY THE INSTANCE CARD IS *ONE* CARD. An earlier cut of this app split the URL
# and the token onto separate cards, reasoning that Penpot has two credentials
# (saga §6.18). It does — but the second is PER-USER and lives on the personal
# page, so the admin section has exactly one credential, like Grafana. The split
# expressed a distinction the admin never sees, and produced a section shaped
# unlike either sibling.
#
# HONEST BUTTONS. Buttons whose engines are not built are rendered but disabled,
# or answer with a plain "not available yet" — never absent, and never silently
# inert. Present-but-disabled keeps the finished shape of the section visible
# from the first release, and enabling one later is deleting an attribute rather
# than redesigning the page.
#
# SCOPE, AND WHERE THE REST LIVES. This file covers the section's SHAPE and the
# buttons' presence/honesty. It deliberately does NOT specify what the buttons
# do — that lives with the behaviour, exactly as in the siblings:
#
#   the pull, and the per-mapping "Sync now"  → reconcile.feature
#   purge                                     → purge.feature
#   the connection test's failure modes       → admin-connection.feature
#   the mapping cards' fields                 → admin-mapping.feature
#
# NEITHER SIBLING HAS A FILE LIKE THIS. Their layout is settled by review rather
# than written down — which is exactly how this app drifted out of alignment in
# the first place, and why the rule is worth stating once, here.
#
# PARTIALLY LIVE. The scenarios that can be driven over occ run for real; the
# ones that assert on rendered markup are @todo until there is a browser-driving
# harness (neither sibling has one either).

Feature: The admin section's shape and actions
  As a Nextcloud admin
  I want this app's settings laid out like its sibling integrations
  So that configuring the third one teaches me nothing new

  Background:
    Given the app is enabled

  # ── the layout ───────────────────────────────────────────────────────────────

  @todo
  Scenario: The section presents four panels in the family's order
    When the admin opens the Penpot settings section
    Then the panels appear in this order:
      | Instance      |
      | Sync Settings |
      | Team mappings |
      | Sync Actions  |
    # Identical to nextcloud-n8n and nextcloud-grafana, deliberately.

  @todo
  Scenario: The Instance card holds both the URL and the service-account token
    When the admin opens the Penpot settings section
    Then the "Instance" card has a Penpot base URL field
    And the "Instance" card has a service-account token field
    And no other admin card asks for a credential
    # One card, because the admin section has exactly one credential. The
    # optional per-user token is on the PERSONAL page — see
    # personal-settings.feature.

  @todo
  Scenario: The token field never echoes a stored token back
    Given the admin has configured the service-account token
    When the admin opens the Penpot settings section
    Then the token field renders blank
    But the card states that a token is currently stored
    # A sensitive field is blank even when populated, so the COPY is the only
    # signal of whether one is set. Both siblings learned this the same way.

  @todo
  Scenario: Every button in the section lives in Sync Actions
    When the admin opens the Penpot settings section
    Then all of the section's action buttons are in the "Sync Actions" panel
    And the "Sync Actions" panel is the last panel in the section

  # ── the actions themselves ───────────────────────────────────────────────────

  @todo
  Scenario: Test connection works today and reports what the account can see
    Given the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    When the admin tests the connection
    Then the connection test reports success
    And the connection test lists at least one Penpot team
    # The one action that is fully live. It reports TEAMS rather than "OK"
    # because Penpot visibility is membership-scoped (saga §6.12/§6.18), so that
    # list is exactly what decides which teams can be mapped.
    #
    # @todo here means "not run FROM THIS FILE", not "unimplemented": the exact
    # same steps run for real in admin-connection.feature, and duplicating a
    # live scenario would just execute it twice. It is restated here so this
    # file reads as the complete inventory of the section's actions.

  @todo
  Scenario: Bulk sync is offered but disabled until the pull exists
    When the admin opens the Penpot settings section
    Then the "Sync from Penpot" button is present
    And the "Sync from Penpot" button is disabled
    And it explains that it arrives with a later release
    # Present-but-disabled, not absent — the shape of the finished section is
    # visible from the first release.

  @todo
  Scenario: There is no "Sync to Penpot" button, ever
    When the admin opens the Penpot settings section
    Then no button offers to push designs to Penpot
    # THE ONE PLACE THIS APP'S LAYOUT DELIBERATELY DIVERGES FROM ITS SIBLINGS,
    # and the divergence is load-bearing. Both of them have a push button. This
    # app is read-only for file CONTENT (saga §6.1) — a permanent boundary, not
    # a phase-ordering gap — so a disabled push button would promise a feature
    # that is never coming.

  @todo
  Scenario: Purge is offered but disabled until the delete machine exists
    When the admin opens the Penpot settings section
    Then the "Purge Nextcloud files" button is present
    And the "Purge Nextcloud files" button is disabled
    # Its behaviour is specified in purge.feature; this only pins its presence
    # and its honesty in the meantime.
