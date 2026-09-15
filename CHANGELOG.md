# Change Log for WPCOM Legacy Redirector

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - Unreleased

**Breaking Changes:**

- Requires PHP 8.2 or later (previously 7.4).
- Requires WordPress 6.4 or later (previously 5.9).
- Removed the `WPCOM_Legacy_Redirector` class, including the public `insert_legacy_redirect()`, `get_redirect_uri()`, and `get_redirect_post_id()` methods. See [UPGRADING.md](UPGRADING.md) for replacements.
- The WP-CLI command set has been redesigned with no backwards-compatible aliases. See [UPGRADING.md](UPGRADING.md) for the full old-to-new command mapping: `insert-redirect` is now `create` (validating by default), `import-from-csv` is now `import`, `export-to-csv` has been removed in favour of `list --format=csv`, and `import-from-meta` flags are now kebab-case.

See [UPGRADING.md](UPGRADING.md) for the full migration guide.

### Added

- The Add/Edit Redirect form now shows the site's home URL alongside the source field, so it is clear the path is read relative to that site. On a subsite at `example.com/blog`, a source of `/foo` means `example.com/blog/foo`, not `example.com/foo`.
- Abilities API registrations on WordPress 6.9 and later, so MCP clients can create, read, update, enable, disable, delete, and validate redirects, and list the external domains redirects point at. Every ability requires the `manage_redirects` capability and goes through the same services as the admin screens and WP-CLI.
- One-off migration of redirect data created by 1.x, covering both the draft post status 1.x left on every redirect and, on subdirectory multisites, the subsite prefix it baked into stored source paths. Runs automatically in batches, or in one pass via the new `wp wpcom-legacy-redirector migrate` command (`--dry-run` supported).
- `wpcom_legacy_redirector_destination_url` filter, the counterpart to `wpcom_legacy_redirector_request_path`, for altering the resolved destination before the redirect is performed. Returning an empty string cancels the redirect.
- Complete DDD (Domain-Driven Design) architecture with Domain, Application, and Infrastructure layers in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- Full multisite/network support with per-site redirect management in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- Comprehensive WP-CLI commands for redirect management: `create`, `get`, `list`, `update`, `delete`, `enable`, `disable`, `validate`, `import`. All commands accept a redirect ID or source path, and the write commands accept multiple redirects at once.
- Targeted validation (`wp wpcom-legacy-redirector validate /path 123`) alongside batch mode, with `--fix` to disable broken redirects.
- Broken redirect export via `wp wpcom-legacy-redirector validate --format=csv`.
- `--porcelain` flag for `create`, printing just the new redirect ID for scripting.
- `--fields` flag for `get` and `list` to limit output columns.
- Status column in CSV export/import for preserving enabled/disabled state.
- Upsert mode for CSV import (`import --mode=upsert`).
- STDIN support for CSV import (`wp wpcom-legacy-redirector import -`).
- Admin UI with list table for viewing, adding, deleting, and validating redirects using new `manage_redirects` capability in https://github.com/Automattic/wpcom-legacy-redirector/pull/159
- "Validate" link in admin UI to check redirect destinations in https://github.com/Automattic/wpcom-legacy-redirector/pull/132
- `wpcom-legacy-redirector find-domains` CLI command for listing redirect target domains.
- Behat end-to-end tests in https://github.com/Automattic/wpcom-legacy-redirector/pull/118
- wp-env configuration for local development.
- GPL v2 LICENSE file.
- CONTRIBUTING.md documentation in https://github.com/Automattic/wpcom-legacy-redirector/pull/157
- Progress bar for `import-from-meta` command.
- `--verbose` flag for `import` and `import-from-meta` commands.
- `--skip-validation` flag for `create` (validation enabled by default, matching UI behaviour).
- `--format` flag for `find-domains`, including a `count` format.
- `Cache-Control: max-age` header on redirect responses, so browsers no longer cache 301s indefinitely. Defaults to one day for 301s and one minute otherwise, filterable via `wpcom_legacy_redirector_redirect_max_age` (return `0` to suppress the header).

### Changed

- Rename `verify` command to `validate` and use `from`/`to` terminology in CLI output for consistency with UI.
- Validation enabled by default for the `create` CLI command (previously skipped).
- External redirect destinations are accepted at creation time from all entry points; the destination host is automatically allowed at redirect time. Previously the CLI rejected hosts missing from the `allowed_redirect_hosts` filter while the admin UI accepted them.
- `import-from-meta` flags renamed to kebab-case: `--skip_dupes` is now `--skip-dupes` (a plain flag) and `--dry_run` is now `--dry-run`.
- Deleting multiple redirects requires a single confirmation (or `--yes`), including when fed from `list --format=ids`.
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

- Redirect lookup no longer decodes the request URL twice. The resolver decoded the URL before parsing it and `SourceUrl` decoded it again, while redirects were created with a single decode. Sources containing a literal `%25` therefore never fired, and requests containing `%23` or `%3F` were parsed as though they held a real fragment or query string, matching the wrong redirect. `SourceUrl` is now the single owner of decoding, so the `wpcom_legacy_redirector_request_path` filter receives the path still percent-encoded.
- On a subdirectory multisite, a source given as a full URL is now stored relative to the subsite, as one typed as a path always was. Previously the subsite prefix was kept, so `https://example.com/subsite1/old-page` was stored as `/subsite1/old-page` while the request for that same URL was looked up as `/old-page`, and the redirect silently never fired. Affected the admin form, `wp wpcom-legacy-redirector create`, and CSV import. Redirects already saved this way are deliberately not migrated - a stored prefixed source is indistinguishable from an intentional redirect for a doubled path such as `example.com/subsite1/subsite1/old-page` - so review sources beginning with the subsite path via `wp wpcom-legacy-redirector list` and recreate any that were meant as full URLs.
- The 1.x data migration's publish and source-repath passes now only run when the site's data actually predates 2.0. Previously a later re-walk of the redirect set (such as the destination-normalisation pass) would also republish redirects that had been deliberately disabled under 2.0, and could rewrite deliberately prefixed sources on subdirectory subsites.
- Attachment destinations are no longer treated as unpublished. Attachments carry the post status `inherit`, so creating, validating, or listing a redirect to a media item wrongly reported it as not published — and `validate --fix` disabled redirects that worked.
- `import --mode=upsert` now updates disabled redirects instead of falling through to the create path, where they were either rejected as duplicates or (with `--skip-validation`) inserted a second time under the same source.
- Saving a redirect now refuses to insert a second one for a source that already has one, in any status.
- Expire negative ("no redirect exists") object cache entries after 5 minutes, so 404 traffic can no longer fill the cache with permanent entries.
- `validate --fix` now disables redirects through the redirect manager, invalidating the lookup cache, instead of writing the post status directly.
- `validate` now detects trashed posts behind relative-path destinations without `--check-urls` (trashing renames the post slug, so the path lookup silently missed them).
- `validate` no longer reports an unrelated post's status for redirects to `/`. The home page has no slug, and the slug lookup was matching any post with an empty `post_name` - which every draft and pending post has - so `validate --fix` could disable working redirects to the home page.
- `import-from-meta` now reports rows that fail to import (failures were previously silently swallowed).
- Resolve WP-CLI synopsis parsing warnings in ValidateCommand.
- Prevent undefined array key warning in get_redirect_data() in https://github.com/Automattic/wpcom-legacy-redirector/pull/153
- CLI insert-redirect now works with post ID destination in https://github.com/Automattic/wpcom-legacy-redirector/pull/155
- Only process published redirects, allowing trash to pause them in https://github.com/Automattic/wpcom-legacy-redirector/pull/154
- PHP warning in Utils::mb_parse_url() in https://github.com/Automattic/wpcom-legacy-redirector/pull/137
- Support non-ASCII characters in redirects in https://github.com/Automattic/wpcom-legacy-redirector/pull/102
- Admin redirect save on subsites in https://github.com/Automattic/wpcom-legacy-redirector/pull/93
- wpcom_vip_add_role_caps capability management in https://github.com/Automattic/wpcom-legacy-redirector/pull/94
- import-from-meta batch size check in https://github.com/Automattic/wpcom-legacy-redirector/pull/68
- Retain submitted field values on validation error in https://github.com/Automattic/wpcom-legacy-redirector/pull/62
- Filter bulk actions dropdown to remove edit option in https://github.com/Automattic/wpcom-legacy-redirector/pull/60
- Exclude redirect post type from ElasticPress indexing.
- Trim whitespace around CSV file path to support drag-and-drop.
- Ensure `POST` var is set during CLI command.

### Security

- Validation notices no longer disclose the title of arbitrary posts via the `ids` query parameter, and render only for users who can manage redirects.
- Enforce TLS certificate verification on destination validation requests, which previously fell back to `'sslverify' => false`.
- Destination validation now uses `wp_safe_remote_get()`/`wp_safe_remote_head()` so stored URLs cannot be used to probe loopback, private, or reserved addresses (SSRF). Validating destinations on other internal hosts now requires opting in via the `http_request_host_is_external` filter; see README.

### Removed

- `export-to-csv` CLI command; use `list --format=csv` instead, or `validate --format=csv` for broken redirects.
- `import-from-csv` CLI command; use `import <file>` instead. Its `--delete` mode is replaced by piping `list --format=ids` into `delete`.
- `insert-redirect` CLI command; use `create` instead.
- `--by=<field>` flag on `get`, `delete`, `enable`, `disable`, and `validate`; these commands now infer whether a redirect is identified by ID or source path.
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
