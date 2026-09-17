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

  Scenario: The pre-2.0 command namespace still works, with a deprecation warning
    Given a WP installation with the Legacy Redirector plugin

    # "I try" rather than "I run": the context only splits STDERR out of the
    # combined output when the exit code is non-zero or the step is a try, and
    # a deprecation warning does not fail the command.
    When I try `wp wpcom-legacy-redirector create /deprecated-ns /target`
    Then the return code should be 0
    And STDERR should contain:
      """
      `wp wpcom-legacy-redirector create` is deprecated since 2.0.0. Use `wp legacy-redirector create` instead.
      """

    When I run `wp legacy-redirector get /deprecated-ns --field=to`
    Then STDOUT should contain:
      """
      /target
      """
