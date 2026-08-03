# COPYING A PROJECT — and the short answer is that you cannot.
#
# Copying a DESIGN duplicates it in Penpot (copy-design.feature). Copying a
# project folder does NOT, and the asymmetry is deliberate rather than an
# omission: Penpot has no duplicate-project operation, so the app would have to
# synthesise one by creating a project and duplicating every design into it. That
# is a bulk write invented by a drag, with no single call to make it atomic and
# no obvious answer for what a half-finished one leaves behind.
#
# So it is refused, visibly. An ordinary folder that merely happens to sit inside
# a mapped folder is unaffected — it is not a project, and copying it is just
# copying a folder.

Feature: Copying a Penpot project folder
  As a Nextcloud user
  I want copying a project folder to be refused rather than half-done
  So that a drag never invents a bulk operation Penpot cannot perform
  Background:
    Given the app is enabled
    And the Penpot base URL points at the test instance
    And the admin has configured the service-account token
    And a Penpot team named "Design Team" is mapped to the folder "Penpot"

  @todo
  Scenario: Copying a project folder is refused, unlike copying a file
    Given the Penpot project "My Stuff" is mirrored as a folder
    When I try to copy that folder
    Then the copy is refused with an explanation
    And no new Penpot project is created
    And no duplicate project folder is left behind
    And copying an individual ".penpot" file remains unaffected
    # DISABLED DELIBERATELY (saga §6.40), not merely unbuilt. Three reasons:
    #  (1) the copy would carry the same project id, so two folders claim one
    #      project — and every file in the copied tree would too;
    #  (2) Nextcloud auto-increments a copy to "My Stuff (2)", which instantly
    #      violates §6.36's names-always-match rule — and "fixing" it by rename
    #      would rename the ORIGINAL Penpot project;
    #  (3) on this cluster a single folder can also carry n8n and Grafana
    #      mappings, so a folder copy asks three independent apps to agree on
    #      what a duplicate means, with no coordination between them.

  @in-nextcloud @gesture @todo
  Scenario: Copying an ordinary folder inside a mapped folder is unaffected
    Given a plain folder "Clients" inside the mapped folder with no Penpot metadata
    When I copy it
    Then the copy succeeds normally
    And Penpot is never contacted
    # Only folders carrying a project id are refused. A mapped folder has to stay
    # usable as an ordinary folder, which is the same rule the tag opt-in rests
    # on (create-project.feature).
