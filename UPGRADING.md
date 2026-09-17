# Upgrading to WPCOM Legacy Redirector 2.0

This guide covers breaking changes and migration steps when upgrading from version 1.x to 2.0.

## Your Existing Redirects Are Migrated Automatically

Three storage changes between 1.x and 2.0 would otherwise stop redirects you already have from working, with no error and no warning:

1. **Version 1.x stored redirects as drafts.** It called `wp_insert_post()` without a `post_status`, so WordPress defaulted each redirect to `draft`. Version 2.0 only serves redirects with the `publish` status.
2. **Where your site is not at the domain root, 1.x stored source paths with that prefix included** (`/subsite1/old-page` on a subsite, `/blog/old-page` on a single site installed at `example.com/blog`). Version 1.x read the raw request path for both storing and matching, so the two agreed. Version 2.0 strips the site's base path from an incoming request and looks up `/old-page`, so it never matches what 1.x wrote.

   This applies to any install whose home URL is below the domain root, not just multisites. If your site lives at `example.com/blog`, you are affected in exactly the same way as a subsite.
3. **Source paths no longer keep a trailing slash.** Version 1.x matched sources exactly, so `/old-page` and `/old-page/` were two separate redirects and covering both meant creating both. Version 2.0 treats them as one, stored under the slash-less form, and canonicalizes incoming requests the same way, so either spelling now finds the redirect. Sources are re-keyed so they match what 2.0 looks up.

   Destinations are untouched: a trailing slash there is part of where the visitor actually lands.

A one-off migration handles all three. It runs automatically in small batches on ordinary page loads after you upgrade, and is version-gated so it runs only once.

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

### When two redirects end up wanting the same source

Re-keying a source can bring two redirects onto one path — most often because you used the old workaround of storing both `/old-page` and `/old-page/`, but also where stripping the base path lands a 1.x source on one that already uses the site-relative form.

Only one redirect can own a path, so the migration decides on the destinations:

- **Both point at the same place.** The spare is redundant, so it is moved to the trash and counted in the migration summary. Nothing is deleted outright, so you can restore it from the Trash view if you disagree.
- **They point at different places.** Only you can say which was meant, so the existing redirect keeps firing and the other is **disabled** and reported. It stays in your list, editable, and plainly not doing anything.

`wp wpcom-legacy-redirector migrate` lists every such conflict, and `--dry-run` shows them before anything is written. Review the disabled redirects afterwards, then either delete them or re-point and re-enable them.

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
$post_id = Container::instance()->repository()->get_id_by_source( SourceUrl::from_string( '/old-page' ) );
```

### Removed WPCOM_Legacy_Redirector Methods

The following public methods are no longer available:

| Method | Replacement |
|--------|-------------|
| `WPCOM_Legacy_Redirector::insert_legacy_redirect()` | `Container::instance()->manager()->create_redirect()` |
| `WPCOM_Legacy_Redirector::get_redirect_uri()` | `Container::instance()->resolver()->get_redirect_data($url)['url']` — returns `null` (not `false`) when no redirect exists |
| `WPCOM_Legacy_Redirector::get_redirect_post_id()` | `Container::instance()->repository()->get_id_by_source(SourceUrl::from_string($url))` |
| `WPCOM_Legacy_Redirector::start()` / `init()` / `maybe_do_redirect()` | Handled automatically by the plugin bootstrap |

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

Version 1.3.0 shipped three commands. All three change:

| 1.x command | 2.0 replacement |
|-------------|-----------------|
| `insert-redirect <from> <to>` | `create <from> <to>` — validates the destination by default, so pass `--skip-validation` for the 1.x behavior, and `--porcelain` to capture the new ID |
| `import-from-csv --csv=<file>` | `import <file>` — `-` reads from STDIN, and `--mode=upsert` updates redirects that already exist |
| `import-from-meta --skip_dupes=1 --dry_run` | `import-from-meta --skip-dupes --dry-run` — flags are now kebab-case, and `--skip_dupes=<bool>` is now the plain flag `--skip-dupes` |

#### Upgrading from a `develop` snapshot

If you have been running the plugin from the `develop` branch rather than the 1.3.0 tag, one further command changes. It never appeared in a tagged release:

| `develop` command | 2.0 replacement |
|-------------------|-----------------|
| `export-to-csv --csv=<file>` | `list --limit=<n> --format=csv > <file>`, or `validate --format=csv > <file>` to export only broken redirects |

`find-domains` keeps its name and gains a `--format` flag, including a `count` format.

Other behavior changes to be aware of:

- `delete`, `enable`, `disable`, `update`, and `validate` accept multiple redirects in one call, e.g. `wp wpcom-legacy-redirector delete /a /b /c --yes`. Every command takes either a redirect ID or a source path and works out which it has been given.
- External destinations still require the host to be allowed via the `allowed_redirect_hosts` filter, as in 1.x, but you now find out at creation time instead of discovering it in production. 1.x accepted any destination and then handed it to `wp_safe_redirect()`, which sent visitors to its fallback of `admin_url()` when the host was not allowed. Creating or updating a redirect to a host the site does not allow is now an error naming the domain, and a stored redirect whose host is not allowed leaves the original 404 in place rather than bouncing visitors to the admin. Run `wp wpcom-legacy-redirector find-domains` to list the domains your existing redirects point at, and allow the ones you intend to keep.

### Destination Validation Uses Safe HTTP Requests

Destination validation (the admin "Validate" action and `wp wpcom-legacy-redirector validate --check-urls`) now uses `wp_safe_remote_get()`/`wp_safe_remote_head()` to prevent SSRF. Requests to loopback, private, and reserved IP addresses are refused, and only ports 80, 443, and 8080 are used (plus the site's own host and port, which are always allowed).

**What this means for you:**

- External destinations and same-site destinations validate as before, including in local development environments.
- Destinations on other internal hosts (intranet/staging networks) will report as failed unless you allow the host via WordPress core's `http_request_host_is_external` filter (see README).
- Destinations on non-standard ports will report as failed in validation. The redirects themselves are unaffected — this only changes validation reporting.

## No Changes Required

The following APIs remain unchanged:

- The `wpcom_legacy_redirector_request_path`, `wpcom_legacy_redirector_redirect_status`, and `wpcom_legacy_redirector_preserve_query_params` filters
- The `wpcom_legacy_redirector_allow_insert` filter — creating redirects from code with no capable user (e.g. unauthenticated front-end code) is still blocked unless this filter returns true; in 2.0 the gate lives in `RedirectManager::create_redirect()`. Note the gate itself has changed: any user with the `manage_redirects` capability may now create redirects from any context without the filter, where 1.x keyed on being in the admin instead of on the capability (see CHANGELOG)
- The `vip-legacy-redirect` post type and its stored data format (no migration needed)

## Using the Container

The `Container` class provides access to all DDD services:

```php
use Automattic\LegacyRedirector\Infrastructure\DI\Container;

// Get the redirect resolver (for lookups)
$resolver = Container::instance()->resolver();

// Get the repository (for direct database access)
$repository = Container::instance()->repository(); // With caching
$repository = Container::instance()->repository();

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
