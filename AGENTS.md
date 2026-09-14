# WPCOM Legacy Redirector

Handles redirects for legacy WordPress.com URLs.

## Project Knowledge

| Property | Value |
|----------|-------|
| **Main file** | `wpcom-legacy-redirector.php` |
| **Text domain** | `wpcom-legacy-redirector` |
| **Namespace** | `Automattic\LegacyRedirector` |
| **Source directory** | `src/` |
| **Version** | 2.0.0-alpha |
| **Requires PHP** | 8.2+ |
| **Requires WP** | 6.4+ |

### Directory Structure

```
wpcom-legacy-redirector/
├── src/
│   ├── Domain/             # Value objects, entities, repository interfaces
│   │   ├── Redirect.php, SourceUrl.php, DestinationUrl.php
│   │   ├── RedirectStatus.php, RedirectCriteria.php
│   │   └── ValidationIssue.php
│   ├── Application/        # Use cases and services
│   │   ├── RedirectManager.php     # Create, update, delete redirects
│   │   ├── RedirectResolver.php    # Runtime redirect resolution
│   │   └── RedirectValidator.php   # Validate redirect rules
│   └── Infrastructure/     # WordPress integration, persistence
│       ├── PostType/       # Custom post type for redirect storage
│       ├── Repository/     # PostTypeRedirectRepository, CachingRedirectRepository
│       ├── Admin/          # Admin UI (BulkActions, RowActions, ListTable, Ajax)
│       └── CLI/            # WP-CLI commands (Create, List, Validate, Import, FindDomains, etc.)
├── tests/
│   ├── Unit/               # Unit tests (Brain Monkey)
│   └── Integration/        # Integration tests (wp-env, separate phpunit config)
├── features/               # Behat feature files (.feature)
├── behat.yml               # Behat configuration
├── phpunit.xml.dist        # Unit test config
├── phpunit-integration.xml.dist  # Integration test config (separate)
├── .github/workflows/      # CI: behat, cs-lint, integration, unit
└── .phpcs.xml.dist         # PHPCS configuration
```

### Key Classes

- **Domain**: `Redirect`, `SourceUrl`, `DestinationUrl` (value objects); `RedirectStatus`, `RedirectCriteria`; `ValidationIssue`; repository interfaces
- **Application**: `RedirectManager` (CRUD), `RedirectResolver` (runtime resolution), `RedirectValidator` (rule validation), `ValidationResult`
- **Infrastructure**: `PostTypeRedirectRepository`, `CachingRedirectRepository`, `RedirectRequestHandler` (performs the HTTP redirect), admin UI components, WP-CLI commands (create, list, validate, import CSV, find domains, etc.)

### Dependencies

- **Dev**: `automattic/vipwpcs`, `yoast/wp-test-utils`, `behat/behat`

## Commands

```bash
composer cs                # Check code standards (PHPCS)
composer cs-fix            # Auto-fix code standard violations
composer lint              # PHP syntax lint
composer test:unit         # Run unit tests
composer test:integration  # Run integration tests (requires wp-env)
composer test:integration-ms  # Run multisite integration tests
composer test              # Run unit + integration tests
composer coverage          # Run tests with HTML coverage report
composer test:behat        # Run Behat BDD scenarios (requires wp-env)
composer test:behat-rerun  # Re-run failed Behat scenarios
```

**Note:** This plugin uses separate PHPUnit config files: `phpunit.xml.dist` for unit tests and `phpunit-integration.xml.dist` for integration tests.

## Conventions

Follow the standards documented in `~/code/plugin-standards/` for full details. Key points:

- **Commits**: Use the `/commit` skill. Favour explaining "why" over "what".
- **PRs**: Use the `/pr` skill. Squash and merge by default.
- **Branch naming**: `feature/description`, `fix/description` from `develop`.
- **Testing**: Three test types here:
  - **Unit tests**: For isolated domain/application logic. Use `Yoast\WPTestUtils\BrainMonkey\YoastTestCase`.
  - **Integration tests**: For WordPress-dependent behaviour. Use `Yoast\WPTestUtils\WPIntegration\TestCase`. Uses a separate config file (`phpunit-integration.xml.dist`).
  - **Behat tests**: For CLI contract verification and critical happy paths only. Keep Behat scenarios minimal (2-5 per command).
- **Code style**: WordPress coding standards via PHPCS. Tabs for indentation.
- **i18n**: All user-facing strings must use the `wpcom-legacy-redirector` text domain.
- **DDD layering**: Domain classes must not depend on Infrastructure or Application.

## Architectural Decisions

- **Domain-Driven Design with CQRS elements**: Three-layer architecture (Domain/Application/Infrastructure). The RedirectManager handles writes, RedirectResolver handles reads. Do not bypass layers.
- **Value objects for URLs**: `SourceUrl` and `DestinationUrl` are distinct value objects (not plain strings). This prevents accidentally swapping source and destination. Always use the appropriate value object.
- **Custom post type for storage**: Redirects are stored as a custom post type for performance and compatibility with VIP Go's infrastructure. Do not switch to custom database tables or options.
- **Caching repository decorator**: `CachingRedirectRepository` wraps `PostTypeRedirectRepository` with object cache. Redirect lookups happen on every page load, so caching is critical for performance.
- **Separate PHPUnit configs**: Unit and integration tests use different PHPUnit config files because integration tests need WordPress loaded and use wp-env.
- **One wp-env environment**: `.wp-env.json` sets `"testsEnvironment": false`, so `wp-env start` brings up a single environment and every test runs in the `cli` container. There is no `tests-cli`.
- **Tier 1 plugin**: This is a well-maintained, modernised plugin. It serves as a reference implementation for the standards.

## Common Pitfalls

- Do not edit WordPress core files or bundled dependencies in `vendor/`.
- Run `composer cs` before committing. CI will reject code standard violations.
- Integration tests require `npx wp-env start` running first.
- **Do not violate DDD layer boundaries**: Domain must not `use` anything from Infrastructure or Application.
- **Value objects are immutable**: Never add setters to `SourceUrl`, `DestinationUrl`, `Redirect`, or other domain value objects. Create new instances instead.
- **Performance is critical**: The `RedirectResolver` runs on every 404 to check for redirects. Any performance regression here affects every request on the site. Always profile changes to the redirect resolution path.
- Do not bypass the caching layer. Always access redirects through the repository interface, which includes caching.
- **Redirect loops**: When adding or modifying redirects, validate that the change does not create redirect loops (A→B→A). The `RedirectValidator` handles this — use it.
- Behat tests are slow (~2 seconds per scenario, against real WordPress in Docker). Do not write Behat tests for edge cases — use integration tests instead.
- The plugin has two PHPUnit config files. Make sure you run the correct one: `composer test:unit` uses `phpunit.xml.dist`, `composer test:integration` uses `phpunit-integration.xml.dist`.
- Do not instantiate services with `new` — use the DI container.
