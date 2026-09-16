Feature: Creating a redirect
  As a user
  I want to create a redirect
  So that specific requests are redirected

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Smoke test: basic redirect creation works via CLI.
  Scenario: Create a redirect to a path
    Given there is a published post with a slug of "bar"

    When I run `wp wpcom-legacy-redirector create /foo /bar`
    Then STDOUT should contain:
      """
      Success: Created redirect
      """
    And STDOUT should contain:
      """
      /foo -> /bar
      """

  # Contract test: duplicate sources are rejected with a non-zero exit code.
  Scenario: Creating a duplicate redirect fails
    Given there is a published post with a slug of "dupe-target"
    And there is a redirect from "/dupe-source" to "/dupe-target"

    When I try `wp wpcom-legacy-redirector create /dupe-source /dupe-target`
    Then STDERR should contain:
      """
      Error:
      """

  # Regression test: a non-ASCII source survives the shell, WP-CLI, and the
  # database on the way in, and comes back out of `get` byte-for-byte.
  Scenario: Create a redirect with a unicode source path
    Given there is a published post with a slug of "unicode-target"

    When I run `wp wpcom-legacy-redirector create /привет-мир /unicode-target`
    Then STDOUT should contain:
      """
      Success: Created redirect
      """

    When I run `wp wpcom-legacy-redirector get /привет-мир`
    Then STDOUT should contain:
      """
      /привет-мир
      """

  # Regression test: astral-plane characters need utf8mb4 all the way down,
  # so an emoji source fails here and nowhere else if a column is too narrow.
  Scenario: Create a redirect with an emoji source path
    Given there is a published post with a slug of "emoji-target"

    When I run `wp wpcom-legacy-redirector create /party-🎉 /emoji-target`
    Then STDOUT should contain:
      """
      Success: Created redirect
      """

    When I run `wp wpcom-legacy-redirector get /party-🎉`
    Then STDOUT should contain:
      """
      /party-🎉
      """

  # Contract test: the encoded form a browser sends and the decoded form an
  # admin types are the same source, so the second create is a duplicate.
  Scenario: A percent-encoded unicode source collides with its decoded form
    Given there is a published post with a slug of "encoded-target"
    And there is a redirect from "/привет" to "/encoded-target"

    When I try `wp wpcom-legacy-redirector create /%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82 /encoded-target`
    Then STDERR should contain:
      """
      Error:
      """

  # Contract test: '/café' and '/caf%C3%A9' are two spellings of one target,
  # so internal destinations store in one canonical form whichever was typed.
  Scenario: An encoded internal destination is stored decoded
    When I run `wp wpcom-legacy-redirector create /vipplug143-source /caf%C3%A9 --skip-validation`
    Then STDOUT should contain:
      """
      Success: Created redirect
      """

    When I run `wp wpcom-legacy-redirector get /vipplug143-source`
    Then STDOUT should contain:
      """
      /café
      """
