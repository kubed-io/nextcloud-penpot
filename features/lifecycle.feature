# Stage 0: the app installs and uninstalls cleanly on a real Nextcloud.
# A clean uninstall is also an app-store rule. No Penpot contact.
#
# Identical shape to both sibling apps (nextcloud-n8n, nextcloud-grafana) — app
# enable/disable has nothing to do with the read-only-vs-bidirectional split that
# makes Penpot Sync architecturally different elsewhere (saga §6.1). This is a
# clean, mechanical port.
#
# LIVE — this is one of the first two features to come off @todo. It runs against
# a real Nextcloud in CI (.github/workflows/integration.yml).

Feature: App install lifecycle
  As a Nextcloud admin
  I want the penpot_sync app to enable and disable cleanly
  So that installing or removing it never leaves the instance broken

  Scenario: Enabling the app
    When the admin enables the app
    Then the app should be enabled
    And the app is installed correctly

  Scenario: Disabling the app
    Given the app is enabled
    When the admin disables the app
    Then the app is not enabled
