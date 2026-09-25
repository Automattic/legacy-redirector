# Upgrading to Legacy Redirector 2.0

This guide covers breaking changes and migration steps when upgrading from version 1.x to 2.0.

## The Plugin Has Been Renamed

The plugin was called **WPCOM Legacy Redirector**. It is now **Legacy Redirector**.

The `WPCOM` prefix was a misnomer. The plugin is not specific to WordPress.com and works on any WordPress install, so the prefix told you something untrue about where it runs. `Legacy` stays, because it still describes what the plugin is for: redirecting the old URLs a site has accumulated, at migration scale.

The rename itself touches no stored redirect data, and you do not need to reinstall or reactivate. Your redirects do change shape in 2.0, for reasons unrelated to the rename, and that is handled for you: see "Your Existing Redirects Are Migrated Automatically" below.

| What | 1.x | 2.0 |
|------|-----|-----|
| Plugin name | WPCOM Legacy Redirector | Legacy Redirector |
| Main file | `wpcom-legacy-redirector.php` | unchanged, so the plugin stays active across the upgrade |
| WP-CLI namespace | `wp wpcom-legacy-redirector` | `wp legacy-redirector`, with the old namespace kept as a deprecated alias for the pre-2.0 commands |
| Filter prefix | `wpcom_legacy_redirector_*` | `legacy_redirector_*`, with the old names kept as deprecated aliases |
| Text domain | `wpcom-legacy-redirector` | `legacy-redirector` |
| Composer package | `automattic/wpcom-legacy-redirector` | unchanged, so your `require` entry keeps working |
| Admin menu | Redirects Manager | Redirects |
| `X-Redirect-By` header | `WPCOM Legacy Redirector` | `Legacy Redirector` |
| Post type | `vip-legacy-redirect` | unchanged |
| Upgrade options | `wpcom_legacy_redirector_db_version` and friends | unchanged |

### What you need to change

**Nothing, immediately.** The Composer package name, the old WP-CLI namespace, and the old filter names all still work, so `composer.json` files, deploy scripts, runbooks, and `mu-plugins` keep working untouched. The CLI namespace and the filters warn when used, and will be removed in a future major version, so migrate those when convenient.

The Composer package stays `automattic/wpcom-legacy-redirector` permanently. Packagist cannot rename a package, so moving would mean publishing a new one and abandoning this one, and every consumer would have to edit their own `require` line to keep receiving updates. That is a real cost for a string that only ever appears inside a `composer.json`, so the name stays where it is.

If you install from a GitHub VCS repository rather than Packagist, the repository has moved to `https://github.com/Automattic/legacy-redirector`. GitHub redirects the old URL permanently, so existing entries keep resolving, but it is worth updating when you next touch the file.

### Renamed filters

The four filters that shipped in 1.3.0 are aliased. Attaching to the old name still works and emits a deprecation notice naming the replacement:

| 1.x filter | 2.0 filter |
|------------|------------|
| `wpcom_legacy_redirector_request_path` | `legacy_redirector_request_path` |
| `wpcom_legacy_redirector_redirect_status` | `legacy_redirector_redirect_status` |
| `wpcom_legacy_redirector_preserve_query_params` | `legacy_redirector_preserve_query_params` |
| `wpcom_legacy_redirector_allow_insert` | `legacy_redirector_allow_insert` |

If a callback is attached to both names, the 2.0 name wins: the deprecated filter runs first and its result is passed into the current one, so a caller that has migrated is never overridden by a callback someone forgot to remove.

Filters introduced in 2.0 (`legacy_redirector_destination_url`, `legacy_redirector_redirect_max_age`, `legacy_redirector_check_destination_reachability`) never shipped under a `wpcom_` name and have no alias.

## Your Existing Redirects Are Migrated Automatically

Four storage changes between 1.x and 2.0 would otherwise stop redirects you already have from working, or leave them stored in a form 2.0 no longer writes, with no error and no warning:

1. **Version 1.x stored redirects as drafts.** It called `wp_insert_post()` without a `post_status`, so WordPress defaulted each redirect to `draft`. Version 2.0 only serves redirects with the `publish` status.
2. **Where your site is not at the domain root, 1.x stored source paths with that prefix included** (`/subsite1/old-page` on a subsite, `/blog/old-page` on a single site installed at `example.com/blog`). Version 1.x read the raw request path for both storing and matching, so the two agreed. Version 2.0 strips the site's base path from an incoming request and looks up `/old-page`, so it never matches what 1.x wrote.

   This applies to any install whose home URL is below the domain root, not just multisites. If your site lives at `example.com/blog`, you are affected in exactly the same way as a subsite.
3. **Source paths no longer keep a trailing slash.** Version 1.x matched sources exactly, so `/old-page` and `/old-page/` were two separate redirects and covering both meant creating both. Version 2.0 treats them as one, stored under the slash-less form, and canonicalizes incoming requests the same way, so either spelling now finds the redirect.

   The same goes for how a source is encoded. Version 1.x ran the stored source and each incoming request through `esc_url_raw()` and compared the results as text, so `/caf%C3%A9` and `/café` were different redirects, and one stored with raw accented characters never matched at all, because browsers send them percent-encoded. Version 2.0 compares decoded forms, so the spellings of one path find the same redirect. Characters `esc_url_raw()` strips, such as `{` and `^`, are still stripped from both sides, as they were in 1.x. Escapes that would change the meaning if decoded stay encoded: `%2F` (a slash inside one path segment), `%3F`, `%23`, `%25` and `%3B`, and in the query `%26`, `%3D` and `%2B`. A `+` in a path is a literal plus, so `/tag/one+two` stays distinct from `/tag/one two`; in a query string it still means a space.

   The migration re-keys every source to the form 2.0 looks up. Without that, a 1.x redirect whose source was stored encoded, or contained a `+` or a space, would stop working after the upgrade with no error.

   A trailing slash on the *destination* is left alone: there it is part of where the visitor actually lands.
4. **Destinations are canonicalized.** A destination pointing at this site by absolute URL (`https://example.com/foo`) is rewritten to the relative form (`/foo`), so anything left absolute afterwards is external by construction. A relative destination is rewritten to the encoding 2.0 produces on save, so whichever of `/café` or `/caf%C3%A9` you originally typed is now stored one way: path and fragment decoded, query string kept percent-encoded.

   With one exception, where the visitor lands does not change — only how the destination is written down. The exception: a destination saved in a web request by a user without `unfiltered_html` (on VIP, everyone) had each `&` stored as `&amp;` by WordPress's HTML filter, and 1.x sent visitors to that escaped URL, whose query has a parameter named `amp;b` where `b` was meant. The migration restores the `&`, so those visitors now land where the redirect was meant to send them. `wp legacy-redirector migrate` counts these as "destination(s) made relative" in both its dry-run and its summary, so the number you see reported covers this pass.

One migration handles all four. It runs automatically in small batches on ordinary page loads after you upgrade (WP-CLI commands never trigger it; `wp legacy-redirector migrate` is how you run it from the command line), and is version-gated, so it walks your redirects once for the upgrade and then stops until a future release changes the stored data again.

### Which passes run when

The first two are corrections to the shape 1.x wrote, so they run **only** where the stored data predates 2.0. Once 2.0 has written data of its own, both readings become ambiguous: a `draft` then means "deliberately disabled" rather than "1.x never set a status", and a source beginning with your home path can be a deliberate double prefix (on a subsite at `/subsite1`, storing `/subsite1/x` is how you redirect the real URL `/subsite1/subsite1/x`). A later version bump re-walks every redirect, so leaving these two ungated would republish redirects you had disabled and rewrite sources you meant.

The source and destination passes carry no such ambiguity — both simply restate a redirect in the one form 2.0 writes — so they run on every walk, including version bumps after 2.0, on any site rather than only one coming from 1.x. Each pass is idempotent, so a redirect already in canonical form is neither rewritten nor counted.

### Large redirect sets

If you have a lot of redirects, run the migration in one pass instead of waiting for it to work through in batches. While it runs, page loads leave the work to it rather than migrating batches of their own:

```bash
wp legacy-redirector migrate
```

Preview it first if you would rather see what will change:

```bash
wp legacy-redirector migrate --dry-run
```

On a network, run it per site:

```bash
wp site list --field=url | xargs -I % wp --url=% legacy-redirector migrate
```

### What the migration will not touch

Under 2.0, a `draft` redirect means "deliberately disabled". The migration therefore only publishes redirects that were **never** published, which WordPress records with a `post_modified_gmt` of `0000-00-00 00:00:00`. Anything you disable after upgrading keeps a real modified date and is left alone.

More broadly, any redirect created or edited after the migration began is skipped by **every** pass, not just the publishing one, including an edit made while a batch is running: it was made under 2.0 rules, so whatever it now says is what you meant. That is what makes the ungated passes safe to re-run on a later version bump.

The migration changes only the fields each pass is about, and nothing else. Every redirect keeps its original post date and modified date, so you can still tell which redirects were added in 2020 rather than seeing the day you upgraded on all of them. It writes straight to the database rather than through `wp_update_post()`, so no post meta is added (a stale audit flag is cleared, as on any save), no slugs are suffixed, and no `save_post` or `transition_post_status` hooks fire for migrated redirects.

Destinations given as a post ID rather than a URL are not rewritten — there is no encoding to canonicalize — and no pass changes where a redirect sends visitors. The source path is re-keyed and the destination re-spelled; the page the visitor arrives at is the same one as before.

### When two redirects end up wanting the same source

Re-keying a source can bring two redirects onto one path — most often because you used the old workaround of storing both `/old-page` and `/old-page/`, but also where stripping the base path lands a 1.x source on one that already uses the site-relative form.

Only one redirect can own a path, so the migration decides on the destinations:

- **Both point at the same place.** The spare is redundant, so it is moved to the trash and counted in the migration summary. Nothing is deleted outright, so you can restore it from the Trash view if you disagree.
- **They point at different places.** Only you can say which was meant, so the existing redirect keeps firing and the other is **disabled** and reported. It stays in your list, plainly not doing anything, and it can't be enabled while the live redirect has its source: two redirects can't answer the same path.

`wp legacy-redirector migrate --dry-run` shows them before anything is written.

#### Settling a disabled duplicate

For each one, decide which destination is right:

- **The live redirect's destination is right.** Delete the disabled one.
- **The disabled one's destination is right.** Change the live redirect's destination to it, then delete the disabled one.

Some disabled duplicates are marked **never fired under 1.x**. Their stored spelling is one no browser ever requested: raw accented characters, such as `/café` where browsers send `/caf%C3%A9`, or, on a site below the domain root, a path without the site's own. Disabling one of those changed nothing for visitors, so unless you want its destination, just delete it. For every other disabled duplicate, visitors who used its spelling now reach the live redirect's destination, so check that's the one you want.

**In the admin**, a notice on the Redirects screen links to the **Duplicate sources** view, which lists every one. Each row's Status column links to the live redirect and says which choice applies: use **Trash** on the disabled redirect, and **Edit** on the live one to change its destination.

**From the command line:**

```bash
# List them, with both destinations and whether each disabled one ever fired
wp legacy-redirector list --duplicates

# Keep the live redirect's destination
wp legacy-redirector delete <disabled ID>

# Keep the disabled one's destination
wp legacy-redirector update <live ID> --to=<destination>
wp legacy-redirector delete <disabled ID>
```

Each drops off the list, and out of the view, once deleted or trashed.

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

The WP-CLI command set has been redesigned. The old `wp wpcom-legacy-redirector` namespace still reaches `find-domains` and `import-from-meta`, with a deprecation warning on STDERR. Commands added in 2.0 are only available under `wp legacy-redirector`. The *subcommands* below have no aliases, so any scripts, runbooks, or cron jobs calling them must be updated. The removed ones stay registered under the old namespace only to fail with an error that names their replacement:

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

- `delete`, `enable`, `disable`, `update`, and `validate` accept multiple redirects in one call, e.g. `wp legacy-redirector delete /a /b /c --yes`. Every command takes either a redirect ID or a source path and works out which it has been given.
- External destinations still require the host to be allowed via the `allowed_redirect_hosts` filter, as in 1.x, but you now find out at creation time instead of discovering it in production. 1.x accepted any destination and then handed it to `wp_safe_redirect()`, which sent visitors to its fallback of `admin_url()` when the host was not allowed. Creating or updating a redirect to a host the site does not allow is now an error naming the domain, and a stored redirect whose host is not allowed leaves the original 404 in place rather than bouncing visitors to the admin. Run `wp legacy-redirector find-domains` to list the domains your existing redirects point at, and allow the ones you intend to keep.

### Destination Validation Uses Safe HTTP Requests

Destination validation (the admin "Validate" action and `wp legacy-redirector validate --check-urls`) now uses `wp_safe_remote_get()`/`wp_safe_remote_head()` to prevent SSRF. Requests to loopback, private, and reserved IP addresses are refused, and only ports 80, 443, and 8080 are used (plus the site's own host and port, which are always allowed).

**What this means for you:**

- External destinations and same-site destinations validate as before, including in local development environments.
- Destinations on other internal hosts (intranet/staging networks) will report as failed unless you allow the host via WordPress core's `http_request_host_is_external` filter (see README).
- Destinations on non-standard ports will report as failed in validation. The redirects themselves are unaffected — this only changes validation reporting.

## No Changes Required

The following behavior remains unchanged:

- The request path, redirect status, and preserve-query-params filters all still work under their 1.x names, and still do the same thing. Only the names are deprecated (see "The Plugin Has Been Renamed" above)
- The allow-insert filter still works under its 1.x name, and creating redirects from code with no capable user (e.g. unauthenticated front-end code) is still blocked unless it returns true; in 2.0 the gate lives in `RedirectManager::create_redirect()`. Note the gate itself has changed: any user with the `manage_redirects` capability may now create redirects from any context without the filter, where 1.x keyed on being in the admin instead of on the capability (see CHANGELOG)
- The `vip-legacy-redirect` post type and its stored data format (no migration needed)
- The `wpcom_legacy_redirector_db_version` option and the other upgrade-tracking options, which keep their names because they hold live state in your database

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
https://github.com/Automattic/legacy-redirector/issues
