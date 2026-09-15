# WPCOM Legacy Redirector

Stable tag: 2.0.0-alpha
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: redirects, 301, legacy, migration, seo
Contributors: automattic, WordPress VIP

A WordPress plugin for handling legacy redirects in a scalable manner. Designed for high-traffic sites with large volumes of redirects.

## At a Glance

- **Scalable**: Handles thousands of redirects efficiently using MD5-indexed lookups
- **Admin UI**: Add and manage redirects through the WordPress admin
- **WP-CLI support**: Bulk import/export via command line
- **Abilities API**: Redirects can be managed by MCP clients and other agents on WordPress 6.9+
- **Multisite compatible**: Works on single sites and multisite networks
- **Query parameter preservation**: Optionally preserve UTM and other tracking parameters
- **VIP-ready**: Built for WordPress VIP environments

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via the WordPress admin
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Tools > Redirects** to add or manage redirects

## Usage

### Adding Redirects via Admin

1. Go to **Tools > Redirects > Add Redirect**
2. Enter the "Redirect From" path (e.g., `/old-page`)
3. Enter the "Redirect To" destination (URL or post ID)
4. Click "Add Redirect"

### Adding Redirects via WP-CLI

```bash
# Add a single redirect
wp wpcom-legacy-redirector create /old-page https://example.com/new-page

# Redirect to an internal post by ID
wp wpcom-legacy-redirector create /old-page 123

# Inspect, list, and manage redirects (by source path or ID)
wp wpcom-legacy-redirector get /old-page
wp wpcom-legacy-redirector list --status=disabled
wp wpcom-legacy-redirector update /old-page --to=/new-page
wp wpcom-legacy-redirector disable /old-page
wp wpcom-legacy-redirector delete /old-page

# Find and disable broken redirects
wp wpcom-legacy-redirector validate --fix

# Import redirects from CSV
wp wpcom-legacy-redirector import /path/to/redirects.csv

# Export redirects to CSV
wp wpcom-legacy-redirector list --limit=100000 --format=csv > /path/to/export.csv
```

### Programmatic Usage

```php
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use function Automattic\LegacyRedirector\container;

// Get the redirect manager via the helper function (recommended for third-party code)
$manager = container()->manager();

// Add a redirect to an external URL
$source      = SourceUrl::from_string( '/old-page' );
$destination = Destination::from_mixed( 'https://example.com/new-page' );
$result      = $manager->create_redirect( $source, $destination );

if ( $result->is_error() ) {
    // Handle error: $result->error_code(), $result->error_message()
}
$redirect_id = $result->redirect_id();

// Add a redirect to an internal post by ID
$source      = SourceUrl::from_string( '/another-old-page' );
$destination = Destination::from_mixed( $post_id );
$result      = $manager->create_redirect( $source, $destination );

// Check if a redirect exists and get its data
$redirect_data = container()->resolver()->get_redirect_data( '/old-page' );
if ( $redirect_data ) {
    $redirect_url    = $redirect_data['url'];
    $redirect_status = $redirect_data['status_code'];
}
```

## Multisite Support

The plugin works on WordPress multisite installations:

- **Per-site redirects**: Each site manages its own redirects independently
- **No cross-site leakage**: Redirects on Site A do not affect Site B
- **WP-CLI support**: Use `--url=site.example.com` to manage specific sites

### Source Paths Are Site-Relative

A source path is always read relative to **that site's** home URL, never the domain root. On a subsite at `example.com/blog`, a source of `/old-page` means `example.com/blog/old-page`.

This matters on subdirectory multisites, where the subsite prefix is not part of the stored path:

```bash
# On a subsite at example.com/blog, these are equivalent - both store /old-page
wp wpcom-legacy-redirector create /old-page /new-page --url=example.com/blog
wp wpcom-legacy-redirector create https://example.com/blog/old-page /new-page --url=example.com/blog

# To redirect the real URL example.com/blog/blog/old-page, the source is /blog/old-page
wp wpcom-legacy-redirector create /blog/old-page /new-page --url=example.com/blog
```

The Add/Edit Redirect screen shows the site's home URL next to the source field so the resolved URL is visible as you type.

### WP-CLI Multisite Examples

```bash
# Add redirect on specific site
wp wpcom-legacy-redirector create /old /new --url=site2.example.com

# Export redirects from specific site
wp wpcom-legacy-redirector list --format=csv --url=site2.example.com > /path/to/export.csv
```

## How It Works

Redirects are stored as a custom post type (`vip-legacy-redirect`) with:

- **MD5 hash** of the source URL in `post_name` (indexed for fast lookups)
- **Original URL** in `post_title` (human-readable)
- **Destination** as either `post_parent` (internal) or `post_excerpt` (external)

The plugin intercepts 404 requests early (priority 0 on `template_redirect`) and performs a redirect if a match is found.

## Hooks and Filters

### Preserve Query Parameters

By default, query parameters are stripped during redirect lookup. To preserve specific parameters (like UTM codes):

```php
add_filter( 'wpcom_legacy_redirector_preserve_query_params', function( $params, $url ) {
    return array( 'utm_source', 'utm_medium', 'utm_campaign' );
}, 10, 2 );
```

### Modify Redirect Status Code

Change the HTTP status code (default: 301):

```php
add_filter( 'wpcom_legacy_redirector_redirect_status', function( $status, $url ) {
    return 302; // Temporary redirect
}, 10, 2 );
```

### Modify Redirect Cache Lifetime

Redirect responses are sent with a `Cache-Control: max-age` header so browsers do not cache them indefinitely. The default is one day for 301 redirects and one minute otherwise:

```php
add_filter( 'wpcom_legacy_redirector_redirect_max_age', function( $max_age, $url, $status ) {
    return HOUR_IN_SECONDS;
}, 10, 3 );
```

Return `0` to suppress the header, e.g. where an edge cache manages redirect caching instead.

### Modify Request Path

Alter the path before redirect lookup. The path is still percent-encoded at this point, since decoding happens during lookup:

```php
add_filter( 'wpcom_legacy_redirector_request_path', function( $path ) {
    return strtolower( $path ); // Case-insensitive matching
} );
```

### Modify the Destination URL

The counterpart to `wpcom_legacy_redirector_request_path`: alter the resolved destination before the redirect is performed. Returning an empty string cancels the redirect.

This is mainly useful where a path suffix is stripped for lookup and needs re-adding to the destination, such as the legacy `/amp/` paired URL structure:

```php
// Match /old-path/amp against the stored /old-path redirect, then re-append /amp.
add_filter( 'wpcom_legacy_redirector_request_path', function( $path ) {
    return preg_replace( '#/amp/?$#', '', $path );
} );

add_filter( 'wpcom_legacy_redirector_destination_url', function( $destination, $path, $url ) {
    return preg_match( '#/amp/?$#', $url ) ? trailingslashit( $destination ) . 'amp/' : $destination;
}, 10, 3 );
```

If your site uses the AMP plugin's default query parameter structure (`?amp=1`) rather than the path suffix, you don't need this filter — use `wpcom_legacy_redirector_preserve_query_params` with `'amp'` instead.

### Validate Destinations on Internal Hosts

Destination validation (the admin "Validate" action and `validate --check-urls`) uses WordPress's safe HTTP functions, which refuse to request loopback, private, and reserved IP addresses. The site's own host is always allowed. If your redirects legitimately point at other internal hosts (e.g. on an intranet or staging network), allow them with WordPress core's filter:

```php
add_filter( 'http_request_host_is_external', function( $external, $host ) {
    return 'internal.example.test' === $host ? true : $external;
}, 10, 2 );
```

Note: even with this filter, safe requests only use ports 80, 443, and 8080 (plus the site's own port). Destinations on other ports will report as failed in validation; the redirects themselves still work.

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `create` | Add a single redirect |
| `get` | Show a single redirect |
| `list` | List, filter, and export redirects |
| `update` | Change a redirect's destination and/or status |
| `delete` | Delete one or more redirects |
| `enable` / `disable` | Toggle one or more redirects |
| `validate` | Find (and optionally disable) broken redirects |
| `import` | Bulk import from CSV file |
| `import-from-meta` | Import from post meta |
| `find-domains` | List destination domains |

For detailed command options, run `wp help wpcom-legacy-redirector`.

## Abilities API

On WordPress 6.9 and later, the plugin registers abilities so that MCP clients and other agents can manage redirects with the same validation, capability checks, and cache invalidation as the admin screens and WP-CLI. Nothing is registered on earlier versions, and abilities are only built when something asks for them, so front-end requests are unaffected.

| Ability | Description |
|---------|-------------|
| `wpcom-legacy-redirector/create-redirect` | Create a redirect |
| `wpcom-legacy-redirector/get-redirect` | Get one redirect, by ID or source path |
| `wpcom-legacy-redirector/list-redirects` | List redirects, with filters and paging |
| `wpcom-legacy-redirector/update-redirect` | Change the destination, and optionally the status, of one or more redirects |
| `wpcom-legacy-redirector/set-redirect-status` | Enable or disable one or more redirects |
| `wpcom-legacy-redirector/delete-redirect` | Delete one or more redirects |
| `wpcom-legacy-redirector/validate-redirects` | Report redirects with broken destinations |
| `wpcom-legacy-redirector/find-redirect-domains` | List the external domains redirects point at |

Every ability requires the `manage_redirects` capability, including the read-only ones. Disabling a redirect keeps it and its destination, but stops serving it to visitors.

## Documentation

See the [Wiki](https://github.com/Automattic/wpcom-legacy-redirector/wiki) for detailed documentation.

## Support

- **Bug reports & features**: [GitHub Issues](https://github.com/Automattic/wpcom-legacy-redirector/issues)
- **VIP customers**: Contact [WordPress VIP Support](https://wpvip.com/wordpress-vip-enterprise-support/)

Please use GitHub Issues only for bug reports and feature requests, not general support questions.

## Contributing

We welcome contributions! See [CONTRIBUTING.md](./CONTRIBUTING.md) for guidelines.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for the full list of changes.

## License

Licensed under `GPL-2.0-or-later`. See [LICENSE](./LICENSE) for details.
