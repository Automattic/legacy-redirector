Feature: Find redirect destination domains
  As a site administrator
  I want to list the domains my redirects point to
  So that I can audit outbound redirect targets

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Smoke test: find-domains lists the unique external destination domains.
  Scenario: List domains for external redirects
    Given "external.example.com" is allowed to be redirected
    And there is a redirect from "/find-domains-source" to "https://external.example.com/some-page"

    When I run `wp legacy-redirector find-domains`
    Then STDOUT should contain:
      """
      external.example.com
      """
