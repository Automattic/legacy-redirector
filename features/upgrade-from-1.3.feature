Feature: Upgrading from version 1.3.0
  As a site administrator upgrading from version 1.x
  I want every old link that redirected under 1.x to redirect the same way under 2.0
  So that upgrading loses no traffic

  # The redirects are stored by 1.3.0's own code, from the files kept byte
  # for byte in tests/Behat/fixtures, and 1.3.0's answers are recorded over
  # HTTP before the upgrade. That tests the migration against what 1.x
  # really wrote and really served, not against a model of it: 1.x ran each
  # source through esc_url_raw() before hashing it, and a hand-built fixture
  # is exactly where a detail like that gets lost.
  Background:
    Given a WP installation with the Legacy Redirector plugin
    # 1.3.0 and 2.0 only redirect 404s, and with plain permalinks an
    # unmatched path is not a 404.
    And I run `wp rewrite structure /%postname%/ --hard`

  Scenario: Every request 1.3.0 redirected still redirects to the same place
    Given version 1.3.0 is active in place of this plugin
    And version 1.3.0 stores these redirects:
      | from                | to                  |
      | /plain              | /dest-plain         |
      | /Mixed/Case         | /dest-case          |
      | /old-page/          | /dest-slash         |
      | /caf%C3%A9-encoded  | /dest-encoded       |
      | /café-raw           | /dest-raw-unicode   |
      | /%E6%97%A5%E6%9C%AC | /dest-cjk           |
      | /trail%C3%A9/       | /dest-encoded-slash |
      | /space here         | /dest-space         |
      | /plus+sign          | /dest-plus          |
      | /tag/one+two/       | /dest-tag           |
      | /100%25-off         | /dest-percent       |
      | /find?q=a+b         | /dest-query-plus    |
      | /find?q=c%2B%2B     | /dest-query-encoded |
      | /page?filter={all}  | /dest-braces        |
      | /a^b                | /dest-caret         |
    # A web request by a user without unfiltered_html, as on VIP, stores the
    # title with '&' escaped by kses after the key was hashed.
    And version 1.3.0 stores these redirects as an author:
      | from                       | to                    |
      | /find/?q=a+b&page=2        | /dest-author-query    |
      | /caf%C3%A9-author/?x=1&y=2 | /dest-author-encoded  |
    # Browsers percent-encode non-ASCII and spaces but send '+', '{' and '^'
    # raw, so these are the requests real visitors make. 1.3.0 never matched
    # a source stored with raw non-ASCII, so that 404 is 1.x's own.
    Then version 1.3.0 answers these requests:
      | request                    | status | to                   |
      | /plain                     | 301    | /dest-plain          |
      | /Mixed/Case                | 301    | /dest-case           |
      | /old-page/                 | 301    | /dest-slash          |
      | /caf%C3%A9-encoded         | 301    | /dest-encoded        |
      | /caf%C3%A9-raw             | 404    |                      |
      | /%E6%97%A5%E6%9C%AC        | 301    | /dest-cjk            |
      | /trail%C3%A9/              | 301    | /dest-encoded-slash  |
      | /space%20here              | 301    | /dest-space          |
      | /plus+sign                 | 301    | /dest-plus           |
      | /tag/one+two/              | 301    | /dest-tag            |
      | /100%25-off                | 301    | /dest-percent        |
      | /find?q=a+b                | 301    | /dest-query-plus     |
      | /find?q=c%2B%2B            | 301    | /dest-query-encoded  |
      | /page?filter={all}         | 301    | /dest-braces         |
      | /a^b                       | 301    | /dest-caret          |
      | /find/?q=a+b&page=2        | 301    | /dest-author-query   |
      | /caf%C3%A9-author/?x=1&y=2 | 301    | /dest-author-encoded |

    When this plugin replaces version 1.3.0
    And I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      Success: Migration complete.
      """
    And every request version 1.3.0 redirected is redirected to the same destination

  # Browsers never sent the raw spelling, so 1.3.0 only ever served the
  # encoded one. The raw row re-keys onto the encoded one's source and is
  # disabled, marked as never having fired, so visitors see no change.
  Scenario: A duplicate that never fired under 1.3.0 is reported as safe to delete
    Given version 1.3.0 is active in place of this plugin
    And version 1.3.0 stores these redirects:
      | from          | to        |
      | /caf%C3%A9-x  | /dest-one |
      | /café-x/      | /dest-two |
    Then version 1.3.0 answers these requests:
      | request       | status | to        |
      | /caf%C3%A9-x  | 301    | /dest-one |
      | /caf%C3%A9-x/ | 404    |           |

    When this plugin replaces version 1.3.0
    And I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      (never fired under 1.x)
      """
    And STDOUT should contain:
      """
      1 of them never fired under 1.x (marked above), so disabling them changed nothing for visitors.
      """
    And every request version 1.3.0 redirected is redirected to the same destination

    When I run `wp legacy-redirector list --duplicates --fields=from,to,never_fired --format=csv`
    Then STDOUT should contain:
      """
      /café-x/,/dest-two,yes
      """

    When I run `wp legacy-redirector list --duplicates --format=ids`
    And save STDOUT as {DUPLICATE_ID}
    And I try `wp legacy-redirector enable {DUPLICATE_ID}`
    Then STDERR should contain:
      """
      already has the source
      """

    When I run `wp legacy-redirector delete {DUPLICATE_ID} --yes`
    And I run `wp legacy-redirector list --duplicates`
    Then STDOUT should contain:
      """
      No redirects are disabled as duplicate sources.
      """
