# Upgrading to WPCOM Legacy Redirector 2.0

This guide covers breaking changes and migration steps when upgrading from version 1.x to 2.0.

## Your Existing Redirects Are Migrated Automatically

Two storage changes between 1.x and 2.0 would otherwise stop every redirect you already have from working, with no error and no warning:

1. **Version 1.x stored redirects as drafts.** It called `wp_insert_post()` without a `post_status`, so WordPress defaulted each redirect to `draft`. Version 2.0 only serves redirects with the `publish` status.
2. **On subdirectory multisites, 1.x stored source paths with the subsite prefix included** (`/subsite1/old-page`), because it prefixed `home_url()` before saving. Version 2.0 strips the subsite prefix from an incoming request and looks up `/old-page`, so it never matches what 1.x wrote.

A one-off migration handles both. It runs automatically in small batches on ordinary page loads after you upgrade, and is version-gated so it runs only once.

### Large redirect sets

If you have a lot of redirects, run the migration in one pass instead of waiting for it to work through in batches:

```bash
wp wpcom-legacy-redirector migrate
```

Preview it first if you would rather see what will change:

```bash
wp wpcom-legacy-redirector migrate --dry-run
```

On a network, run it per site:

```bash
wp site list --field=url | xargs -I % wp --url=% wpcom-legacy-redirector migrate
```

### What the migration will not touch

Under 2.0, a `draft` redirect means "deliberately disabled". The migration therefore only publishes redirects that were **never** published, which WordPress records with a `post_modified_gmt` of `0000-00-00 00:00:00`. Anything you disable after upgrading keeps a real modified date and is left alone.

If rewriting a subsite path would collide with a redirect that already uses the subsite-relative form, the migration leaves the 1.x redirect untouched and reports the clash rather than silently discarding one of them. `wp wpcom-legacy-redirector migrate` lists any such conflicts for you to reconcile by hand.

## Breaking Changes

### Removal of the WPCOM_Legacy_Redirector Class

The global `WPCOM_Legacy_Redirector` class has been removed. Redirect creation is now handled by the `RedirectManager` service and lookups by the `RedirectResolver` service, both available via the `Container` class.

#### Migration Examples

**Before (1.x):**
```php
// Insert a redirect (returns true or WP_Error)
$result = WPCOM_Legacy_Redirector::insert_legacy_redirect( '/old-page', 'https://example.com/new-page' );
if ( is_wp_error( $result ) ) {
    // Handle error
}

// Look up a redirect (returns the destination URL, or false if none)
$redirect_uri = WPCOM_Legacy_Redirector::get_redirect_uri( '/old-page' );

// Get the redirect's post ID
$post_id = WPCOM_Legacy_Redirector::get_redirect_post_id( '/old-page' );
```

**After (2.0):**
```php
use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;

// Insert a redirect using the new API
$manager     = Container::instance()->manager();
$source      = SourceUrl::from_string( '/old-page' );
$destination = Destination::from_mixed( 'https://example.com/new-page' );
$result      = $manager->create_redirect( $source, $destination, $validate = false );

if ( $result->is_error() ) {
    // Handle error
    $error_code    = $result->error_code();
    $error_message = $result->error_message();
}

// Get the redirect ID
$redirect_id = $result->redirect_id();

// Redirect to a post ID
$source      = SourceUrl::from_string( '/another-page' );
$destination = Destination::from_mixed( 123 ); // post ID
$result      = $manager->create_redirect( $source, $destination );

// Look up a redirect (returns array with 'url' and 'status_code' keys, or null if none)
$redirect_data = Container::instance()->resolver()->get_redirect_data( '/old-page' );
if ( null !== $redirect_data ) {
    $uri    = $redirect_data['url'];
    $status = $redirect_data['status_code'];
}

// Get the redirect's post ID
$post_id = Container::instance()->inner_repository()->get_id_by_source( SourceUrl::from_string( '/old-page' ) );
```

### Removed WPCOM_Legacy_Redirector Methods

The following public methods are no longer available:

| Method | Replacement |
|--------|-------------|
| `WPCOM_Legacy_Redirector::insert_legacy_redirect()` | `Container::instance()->manager()->create_redirect()` |
| `WPCOM_Legacy_Redirector::get_redirect_uri()` | `Container::instance()->resolver()->get_redirect_data($url)['url']` — returns `null` (not `false`) when no redirect exists |
| `WPCOM_Legacy_Redirector::get_redirect_post_id()` | `Container::instance()->inner_repository()->get_id_by_source(SourceUrl::from_string($url))` |
| `WPCOM_Legacy_Redirector::start()` / `init()` / `maybe_do_redirect()` | Handled automatically by the plugin bootstrap |

### WP-CLI Changes

- `insert-redirect` now validates the redirect by default, matching the admin UI. Pass `--skip-validation` to restore the 1.x behaviour of inserting without validation.
- `import-from-meta` flags are now kebab-case: `--dry_run` is now `--dry-run`, and `--skip_dupes=<bool>` is now the boolean flag `--skip-dupes` (pass it to skip duplicates, omit it otherwise).
- `import-from-csv` accepts the same arguments as before. New commands (`list`, `get`, `update`, `delete`, `enable`, `disable`, `validate`, `export-to-csv`, `find-domains`) are additions, not replacements.

### Using RedirectCreationResult

The new `create_redirect()` method returns a `RedirectCreationResult` object instead of mixed types:

```php
$result = $manager->create_redirect( $source, $destination, $validate );

// Check for errors
if ( $result->is_error() ) {
    echo $result->error_code();    // e.g., 'duplicate-redirect-uri'
    echo $result->error_message(); // Human-readable message
}

// Get the redirect ID on success
$id = $result->redirect_id();
```

### WP-CLI Command Changes

The WP-CLI command set has been redesigned. There are no backwards-compatible aliases, so any scripts, runbooks, or cron jobs calling the old commands must be updated:

| 1.x command | 2.0 replacement |
|-------------|-----------------|
| `insert-redirect <from> <to>` | `create <from> <to>` (add `--porcelain` to capture the new ID) |
| `import-from-csv --csv=<file>` | `import <file>` (`-` reads from STDIN) |
| `import-from-csv --csv=<file> --update` | `import <file> --mode=upsert` |
| `import-from-csv --csv=<file> --delete` | `list --format=ids ... \| xargs wp wpcom-legacy-redirector delete --yes` |
| `export-to-csv --csv=<file>` | `list --limit=<n> --format=csv > <file>` |
| `export-to-csv --broken-only [--check-urls]` | `validate [--check-urls] --format=csv > <file>` |
| `<command> <id> --by=id` | `<command> <id>` (ID or source path is inferred) |
| `update <source> <destination>` | `update <redirect> --to=<destination>` |
| `import-from-meta --skip_dupes=1 --dry_run` | `import-from-meta --skip-dupes --dry-run` |

Other behaviour changes to be aware of:

- `delete`, `enable`, `disable`, `update`, and `validate` accept multiple redirects in one call, e.g. `wp wpcom-legacy-redirector delete /a /b /c --yes`.
- `validate` no longer checks destination URLs over HTTP by default when given a single redirect; pass `--check-urls` explicitly (this now behaves the same in batch and targeted modes).
- `create` no longer rejects external destinations whose host is missing from the `allowed_redirect_hosts` filter. This matches the admin UI: the destination host is automatically allowed at redirect time.

## No Changes Required

The following APIs remain unchanged:

- The `wpcom_legacy_redirector_request_path`, `wpcom_legacy_redirector_redirect_status`, and `wpcom_legacy_redirector_preserve_query_params` filters
- The `wpcom_legacy_redirector_allow_insert` filter — creating redirects outside WP-CLI and the admin (e.g. from front-end code) is still blocked unless this filter returns true; in 2.0 the gate lives in `RedirectManager::create_redirect()`
- The `vip-legacy-redirect` post type and its stored data format (no migration needed)

## Using the Container

The `Container` class provides access to all DDD services:

```php
use Automattic\LegacyRedirector\Infrastructure\DI\Container;

// Get the redirect resolver (for lookups)
$resolver = Container::instance()->resolver();

// Get the repository (for direct database access)
$repository = Container::instance()->repository(); // With caching
$repository = Container::instance()->inner_repository(); // Without caching

// Get the validator
$validator = Container::instance()->validator();

// Get the manager (for admin operations)
$manager = Container::instance()->manager();
```

## Testing

If you have tests that depend on the `WPCOM_Legacy_Redirector` class, update them to use the DDD services:

```php
// Before
$this->assertSame( $expected_uri, WPCOM_Legacy_Redirector::get_redirect_uri( $url ) );

// After
$redirect_data = Container::instance()->resolver()->get_redirect_data( $url );
$this->assertSame( $expected_uri, $redirect_data['url'] );
```

## Questions?

If you encounter issues upgrading, please open an issue at:
https://github.com/Automattic/WPCOM-Legacy-Redirector/issues
