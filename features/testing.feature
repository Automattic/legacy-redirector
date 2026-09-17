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

  Scenario: WP-CLI recognizes wpcom-legacy-redirector commands when the plugin is loaded
    Given a WP installation with the WPCOM Legacy Redirector plugin

    When I run `wp wpcom-legacy-redirector --help`
    Then STDOUT should contain:
      """
      Manage redirects added via the WPCOM Legacy Redirector plugin.
      """
