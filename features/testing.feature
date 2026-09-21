  Feature: The Behat tests are configured correctly

  Scenario: WP-CLI loads for your tests
    Given a WP install

    When I run `wp eval 'echo "Hello world.";'`
    Then STDOUT should be:
      """
      Hello world.
      """

  Scenario: WP-CLI recognizes plugin commands
    Given a WP install

    When I run `wp plugin --help`
    Then STDOUT should contain:
      """
      Manages plugins, including installs, activations, and updates.
      """

  Scenario: WP-CLI recognizes legacy-redirector commands when the plugin is loaded
    Given a WP installation with the Legacy Redirector plugin

    When I run `wp legacy-redirector --help`
    Then STDOUT should contain:
      """
      Manage redirects added via the Legacy Redirector plugin.
      """

  Scenario: The pre-2.0 command namespace still runs pre-2.0 commands, with a deprecation warning
    Given a WP installation with the Legacy Redirector plugin
    And "external.example.com" is allowed to be redirected
    And there is a redirect from "/deprecated-ns" to "https://external.example.com/"

    # "I try" rather than "I run": the context only splits STDERR out of the
    # combined output when the exit code is non-zero or the step is a try, and
    # a deprecation warning does not fail the command.
    When I try `wp wpcom-legacy-redirector find-domains`
    Then the return code should be 0
    And STDOUT should contain:
      """
      external.example.com
      """
    And STDERR should contain:
      """
      `wp wpcom-legacy-redirector find-domains` is deprecated since 2.0.0. Use `wp legacy-redirector find-domains` instead.
      """

  Scenario: The pre-2.0 namespace does not alias commands added in 2.0, and removed ones name their replacement
    Given a WP installation with the Legacy Redirector plugin

    When I try `wp wpcom-legacy-redirector insert-redirect /old /new`
    Then the return code should be 1
    And STDERR should contain:
      """
      `wp wpcom-legacy-redirector insert-redirect` was removed in 2.0.0. Use `wp legacy-redirector create <from> <to>` instead.
      """

    When I try `wp wpcom-legacy-redirector create /new /target`
    Then the return code should be 1
    And STDERR should contain:
      """
      is not a registered subcommand
      """
