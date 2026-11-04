  Feature: The Behat tests are configured correctly

  Scenario: WP-CLI loads for your tests
    Given a WP install

    When I run `wp eval 'echo "Hello world.";'`
    Then STDOUT should be:
      """
      Hello world.
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

    When I run `wp post create --post_title=deprecated-meta --post_status=publish --porcelain`
    And save STDOUT as {POST_ID}
    And I run `wp post meta add {POST_ID} legacy_redirect_url /deprecated-meta-source`
    And I try `wp wpcom-legacy-redirector import-from-meta --meta-key=legacy_redirect_url`
    Then the return code should be 0
    And STDOUT should contain:
      """
      All of your redirects have been imported.
      """
    And STDERR should contain:
      """
      `wp wpcom-legacy-redirector import-from-meta` is deprecated since 2.0.0. Use `wp legacy-redirector import-from-meta` instead.
      """

  Scenario Outline: A command removed in 2.0 fails and names its replacement
    Given a WP installation with the Legacy Redirector plugin

    When I try `wp wpcom-legacy-redirector <removed>`
    Then the return code should be 1
    And STDERR should contain:
      """
      `wp wpcom-legacy-redirector <command>` was removed in 2.0.0. Use `wp legacy-redirector <replacement>
      """

    Examples:
      | removed                        | command         | replacement |
      | insert-redirect /old /new      | insert-redirect | create      |
      | import-from-csv /tmp/none.csv  | import-from-csv | import      |
      | export-to-csv                  | export-to-csv   | list        |

  Scenario: The pre-2.0 namespace does not alias commands added in 2.0
    Given a WP installation with the Legacy Redirector plugin

    When I try `wp wpcom-legacy-redirector create /new /target`
    Then the return code should be 1
    And STDERR should contain:
      """
      is not a registered subcommand
      """
