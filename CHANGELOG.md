# Change Log for WPCOM Legacy Redirector

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

**Breaking Changes:**

- Requires PHP 8.2 or later (previously 7.4).
- Requires WordPress 6.4 or later (previously 5.9).
- Removed the `WPCOM_Legacy_Redirector` class, including the public `insert_legacy_redirect()`, `get_redirect_uri()`, and `get_redirect_post_id()` methods. See [UPGRADING.md](UPGRADING.md) for replacements.
- The `insert-redirect` WP-CLI command now validates by default; pass `--skip-validation` for the previous behaviour.
- The `import-from-meta` WP-CLI command flags are now kebab-case: `--dry_run` is `--dry-run`, and `--skip_dupes=<bool>` is the boolean flag `--skip-dupes`.

See [UPGRADING.md](UPGRADING.md) for the full migration guide.

### Added

- One-off migration of redirect data created by 1.x, covering both the draft post status 1.x left on every redirect and, on subdirectory multisites, the subsite prefix it baked into stored source paths. Runs automatically in batches, or in one pass via the new `wp wpcom-legacy-redirector migrate` command (`--dry-run` supported).
- Complete DDD (Domain-Driven Design) architecture with Domain, Application, and Infrastructure layers in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- Full multisite/network support with per-site redirect management in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- Comprehensive WP-CLI commands for redirect management: `list`, `get`, `delete`, `update`, `enable`, `disable`, `validate`.
- Single redirect validation mode (`wp wpcom-legacy-redirector validate /path` or `--by=id`).
- Performance reporting for batch validation (shows time elapsed and rate).
- Broken redirect filtering for CSV export (`--broken-only` and `--check-urls` flags).
- Status column in CSV export/import for preserving enabled/disabled state.
- Update and delete modes for CSV import (`--update` and `--delete` flags).
- CSV export CLI command in https://github.com/Automattic/wpcom-legacy-redirector/pull/35
- Admin UI with list table for viewing, adding, deleting, and validating redirects using new `manage_redirects` capability in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- "Validate" link in admin UI to check redirect destinations in https://github.com/Automattic/wpcom-legacy-redirector/pull/132
- `wpcom-legacy-redirector find-domains` CLI command for listing redirect target domains.
- Behat end-to-end tests in https://github.com/Automattic/wpcom-legacy-redirector/pull/118
- wp-env configuration for local development.
- GPL v2 LICENSE file.
- CONTRIBUTING.md documentation in https://github.com/Automattic/wpcom-legacy-redirector/pull/157
- Progress bar for `import-from-meta` command.
- `--verbose` flag for `import-from-meta` and `import-from-csv` commands.
- `--skip-validation` flag for insert-redirect (validation enabled by default, matching UI behaviour).

### Changed

- Rename `verify` command to `validate` and use `from`/`to` terminology in CLI output for consistency with UI.
- Validation enabled by default for `insert-redirect` CLI command (previously skipped).
- Improved terminology to be more inclusive in https://github.com/Automattic/wpcom-legacy-redirector/pull/78
- Split unit and integration tests with expanded coverage (357 total tests).
- Use `x_redirect_by` header instead of custom header in https://github.com/Automattic/wpcom-legacy-redirector/pull/70
- Improved adherence to WPCS and VIPCS coding standards in https://github.com/Automattic/wpcom-legacy-redirector/pull/119
- Tighten custom post type arguments in https://github.com/Automattic/wpcom-legacy-redirector/pull/67
- Prioritise redirect from_url validation to avoid duplicates in https://github.com/Automattic/wpcom-legacy-redirector/pull/61
- Exclude redirect post type from search in https://github.com/Automattic/wpcom-legacy-redirector/pull/45
- Use `WP_CLI::error` to halt operation on failed insert.
- Return error if no redirects found for meta key.
- Performance improvements for `import-from-meta` command.
- Improved CLI command documentation in https://github.com/Automattic/wpcom-legacy-redirector/pull/72
- Expand README with usage examples and architecture details in https://github.com/Automattic/wpcom-legacy-redirector/pull/157

### Fixed

- Resolve WP-CLI synopsis parsing warnings in ValidateCommand.
- Prevent undefined array key warning in get_redirect_data() in https://github.com/Automattic/wpcom-legacy-redirector/pull/153
- CLI insert-redirect now works with post ID destination in https://github.com/Automattic/wpcom-legacy-redirector/pull/155
- Only process published redirects, allowing trash to pause them in https://github.com/Automattic/wpcom-legacy-redirector/pull/154
- PHP warning in Utils::mb_parse_url() in https://github.com/Automattic/wpcom-legacy-redirector/pull/137
- Support non-ASCII characters in redirects in https://github.com/Automattic/wpcom-legacy-redirector/pull/102
- Admin redirect save on subsites in https://github.com/Automattic/wpcom-legacy-redirector/pull/93
- wpcom_vip_add_role_caps capability management in https://github.com/Automattic/wpcom-legacy-redirector/pull/94
- import-from-meta batch size check in https://github.com/Automattic/wpcom-legacy-redirector/pull/68
- Allow self-signed certificates to pass 404 check in https://github.com/Automattic/wpcom-legacy-redirector/pull/65
- Retain submitted field values on validation error in https://github.com/Automattic/wpcom-legacy-redirector/pull/62
- Filter bulk actions dropdown to remove edit option in https://github.com/Automattic/wpcom-legacy-redirector/pull/60
- Exclude redirect post type from ElasticPress indexing.
- Trim whitespace around CSV file path to support drag-and-drop.
- Ensure `POST` var is set during CLI command.

### Removed

- Remove deprecated `wpcom_vip_get_page_by_path()` function in https://github.com/Automattic/wpcom-legacy-redirector/pull/135
- Remove obsolete Travis CI configuration in https://github.com/Automattic/wpcom-legacy-redirector/pull/156
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
