# Change Log for WPCOM Legacy Redirector

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

2.0.0 is a rewrite of the plugin. The entries below describe the difference between 1.3.0, the last tagged release, and 2.0.0; work that only fixed code added during the 2.0 development cycle is folded into the entry for the feature it belongs to.

**Breaking Changes:**

- Requires PHP 8.3 or later (previously 7.4).
- Requires WordPress 6.8 or later (previously 5.9).
- Removed the `WPCOM_Legacy_Redirector` class, including the public `insert_legacy_redirect()`, `get_redirect_uri()`, and `get_redirect_post_id()` methods. See [UPGRADING.md](UPGRADING.md) for replacements.
- The WP-CLI command set has been redesigned with no backwards-compatible aliases. See [UPGRADING.md](UPGRADING.md) for the full old-to-new command mapping: `insert-redirect` is now `create` (validating by default), `import-from-csv` is now `import`, `export-to-csv` has been removed in favor of `list --format=csv`, and `import-from-meta` flags are now kebab-case.
- On subdirectory multisites, redirect sources are stored relative to the subsite. A redirect for `example.com/blog/old-page` is now stored as `/old-page`, where 1.x stored `/blog/old-page`. Existing data is repathed automatically by the migration, but `list` output, CSV exports, and anything reading the stored source directly will see the shorter form.
- Redirect sources no longer keep a trailing slash: `/old-page` and `/old-page/` are one redirect, stored under the slash-less form. 1.x treated them as two, so covering both meant creating both. Existing rows are re-keyed by the migration; where a site stored both forms, an identical pair is merged and a pair pointing at different destinations is reported. See [UPGRADING.md](UPGRADING.md).

See [UPGRADING.md](UPGRADING.md) for the full migration guide.

### Added

- Admin UI for managing redirects: a list table with status views for viewing, adding, editing, deleting, and validating redirects, all gated on the new `manage_redirects` capability, in https://github.com/Automattic/wpcom-legacy-redirector/pull/159 and https://github.com/Automattic/wpcom-legacy-redirector/pull/132
- The Add/Edit Redirect form shows the site's home URL alongside the source field, so it is clear the path is read relative to that site. On a subsite at `example.com/blog`, a source of `/foo` means `example.com/blog/foo`, not `example.com/foo`.
- Full multisite/network support with per-site redirect management in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- One-off migration of redirect data created by 1.x, covering the draft post status 1.x left on every redirect, the subsite prefix 1.x baked into stored source paths on subdirectory multisites, and destination URLs pointing back at the site itself (now stored in their relative, canonically encoded form). Runs automatically in batches, or in one pass via the new `wp wpcom-legacy-redirector migrate` command (`--dry-run` supported).
- Redesigned WP-CLI command set: `create`, `get`, `list`, `update`, `delete`, `enable`, `disable`, `validate`, `import`, `find-domains`, and `migrate`. Every command accepts a redirect ID or a source path, and the write commands accept multiple redirects at once.
  - `create` validates the destination by default (`--skip-validation` to opt out, matching the UI), and `--porcelain` prints just the new redirect ID for scripting.
  - `get` and `list` accept `--fields` to limit output columns; `list --format=csv` replaces `export-to-csv`, and `list --format=ids` can be piped into `delete`.
  - `delete`, `enable`, and `disable` take multiple redirects behind a single confirmation (or `--yes`).
  - `validate` runs in batch or targeted mode (`wp wpcom-legacy-redirector validate /path 123`), disables broken redirects with `--fix`, and exports broken ones with `--format=csv`. Destination checks use `wp_safe_remote_get()`/`wp_safe_remote_head()`, so stored URLs cannot be used to probe loopback, private, or reserved addresses (SSRF); validating destinations on other internal hosts requires opting in via the `http_request_host_is_external` filter, as described in the README.
  - `import` replaces `import-from-csv`, reads from STDIN (`import -`), supports `--mode=upsert` and `--verbose`, and round-trips a status column so enabled/disabled state survives export and import.
  - `find-domains` lists the domains redirects point at, with `--format` (including a `count` format).
- Abilities API registrations on WordPress 6.9 and later, so MCP clients can create, read, update, enable, disable, delete, and validate redirects, and list the external domains redirects point at. Every ability requires the `manage_redirects` capability and goes through the same services as the admin screens and WP-CLI.
- `wpcom_legacy_redirector_destination_url` filter, the counterpart to `wpcom_legacy_redirector_request_path`, for altering the resolved destination before the redirect is performed. Returning an empty string cancels the redirect.
- `Cache-Control: max-age` header on redirect responses, so browsers no longer cache 301s indefinitely. Defaults to one day for 301s and one minute otherwise, filterable via `wpcom_legacy_redirector_redirect_max_age` (return `0` to suppress the header).
- Progress bar and `--verbose` flag for the `import-from-meta` command.
- Complete DDD (Domain-Driven Design) architecture with Domain, Application, and Infrastructure layers in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- Development tooling: Behat end-to-end tests in https://github.com/Automattic/wpcom-legacy-redirector/pull/118, wp-env configuration for local development, WPCS and VIPCS coding standards enforced in CI in https://github.com/Automattic/wpcom-legacy-redirector/pull/119, and split unit and integration test suites.
- GPL v2 LICENSE file, CONTRIBUTING.md, and an expanded README covering usage examples and architecture, in https://github.com/Automattic/wpcom-legacy-redirector/pull/157

### Changed

- The `wpcom_legacy_redirector_redirect_status` filter now validates its result: values that are not a valid HTTP redirect status code (301, 302, 303, 307, 308) are replaced with the default 301 instead of being passed to `wp_safe_redirect()`. The Cache-Control max-age default also now treats 308 as permanent (one day), not just 301.
- Redirect creation is now allowed for any user with the `manage_redirects` capability, wherever the request arrives from — including the REST API and Abilities/MCP clients. Previously the gate allowed any admin-context request regardless of capability, and blocked everything else (including capable users) unless the `wpcom_legacy_redirector_allow_insert` filter opted in. The filter still governs creation from contexts with no capable user, such as unauthenticated front-end code.
- Only enabled (published) redirects are served, so a redirect can be paused by disabling it rather than deleted, in https://github.com/Automattic/wpcom-legacy-redirector/pull/154
- Internal destinations are stored in one canonical form whatever was typed: absolute URLs pointing at this site become relative, and `/café` and `/caf%C3%A9` both store as `/café` (the path and fragment are decoded; the query keeps its percent-encoding, since its values have sub-structure a decode would corrupt). 1.x stored whichever spelling was entered, so one target could be two different strings; the migration rewrites existing rows to match.
- External redirect destinations are refused at creation time unless the site allows the host via the `allowed_redirect_hosts` filter, with an error naming the domain. 1.x accepted them silently and then sent every visitor to `wp_safe_redirect()`'s fallback, `admin_url()` — so a redirect that looked fine in the admin screen quietly bounced visitors to the login page. A stored destination whose host is no longer allowed now leaves the 404 in place rather than bouncing to the admin.
- Use `wp wpcom-legacy-redirector find-domains` to list the domains existing redirects point at when populating the filter.
- Source paths containing non-ASCII or percent-encoded characters are matched correctly, in https://github.com/Automattic/wpcom-legacy-redirector/pull/102, including where home is not the domain root — a source saved for `example.com/日本/ページ` resolves for the percent-encoded path a browser actually requests. Matching no longer depends on the server's locale either: every URL the plugin parses now goes through a UTF-8 safe parser, where PHP's own `parse_url()` corrupts raw multibyte bytes on hosts whose `LC_CTYPE` treats the C1 range as control characters.
- `import-from-meta`: flags renamed to kebab-case (`--skip_dupes` is now the plain flag `--skip-dupes`, and `--dry_run` is now `--dry-run`), rows that fail to import are reported rather than silently swallowed, an error is returned when the meta key matches no redirects, and the batch query is faster.
- `x_redirect_by` header used instead of a custom header in https://github.com/Automattic/wpcom-legacy-redirector/pull/70
- Redirect post type arguments tightened, and the post type excluded from site search in https://github.com/Automattic/wpcom-legacy-redirector/pull/67 and https://github.com/Automattic/wpcom-legacy-redirector/pull/45, and from ElasticPress indexing.
- `WP_CLI::error` halts the operation on a failed insert, rather than continuing.
- Improved terminology to be more inclusive in https://github.com/Automattic/wpcom-legacy-redirector/pull/78

### Fixed

- A redirect created for `/old-page` now also fires for a request to `/old-page/`, and vice versa. 1.x required an exact match, so the documented workaround was to store both forms. Reported in https://github.com/Automattic/wpcom-legacy-redirector/issues/50; the approach follows the analysis by @bdtech in that thread and in https://github.com/Automattic/wpcom-legacy-redirector/pull/54
- Negative ("no redirect exists") object cache entries now expire after five minutes. 1.x cached them indefinitely, so 404 traffic could fill the object cache with permanent entries.
- Whitespace around the CSV file path is trimmed, so a path dragged and dropped into the terminal is accepted.

### Removed

- `insert-redirect` CLI command; use `create` instead.
- `import-from-csv` CLI command; use `import <file>` instead. Its `--delete` mode is replaced by piping `list --format=ids` into `delete`.
- `export-to-csv` CLI command. It never appeared in a tagged 1.x release, but has been on `develop` since 2019: use `list --format=csv` instead, or `validate --format=csv` for broken redirects.
- Obsolete Travis CI configuration in https://github.com/Automattic/wpcom-legacy-redirector/pull/156
- Drop support for PHP 5.3-8.1.
- Drop support for WordPress < 6.4.

## [1.3.0] - 2016-03-29

### Added

- `wpcom_legacy_redirector_preserve_query_params` filter to allow for the safelisting of params that should be passed through to the redirected URL.

### Changed

- Updated logic to check `wp_parse_url()` query component as the Request value will not be set for test purposes.
- Updated unit tests.

### Fixed

- Fix "Undefined variable $row at line 98" PHP notice.

## [1.2.0] - 2016-07-07

### Added

- Composer support
- `wpcom_legacy_redirector_redirect_status` filter for redirect status code (props spacedmonkey)
- `wpcom_legacy_redirector_redirect_allow_insert` filter to enable inserts outside of WP-CLI.

### Fixed

- Reset cache when a redirect post does not exist.
- Fix for WP-CLI check.

## [1.1.0] - 2016-03-29

### Added

- Unit tests

### Fixed

- Fix bug with query string URLs

## 1.0.0 - 2016-02-27

Initial release.

[2.0.0]: https://github.com/Automattic/wpcom-legacy-redirector/compare/1.3.0...2.0.0
[1.3.0]: https://github.com/Automattic/wpcom-legacy-redirector/compare/1.2.0...1.3.0
[1.2.0]: https://github.com/Automattic/wpcom-legacy-redirector/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/Automattic/wpcom-legacy-redirector/compare/1.0.0...1.1.0
