# Legacy Redirector

Stable tag: 2.0.0-alpha
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.3
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
3. Navigate to the top-level **Redirects** menu to add or manage redirects

## Usage

### Adding Redirects via Admin

1. Go to **Redirects > Add Redirect**
2. Enter the "Redirect From" path (e.g., `/old-page`)
3. Enter the "Redirect To" destination (URL or post ID)
4. Click "Add Redirect"

### Adding Redirects via WP-CLI

```bash
# Add a single redirect, or point one at an internal post by ID
wp legacy-redirector create /old-page https://example.com/new-page
wp legacy-redirector create /old-page 123

# Inspect and manage redirects, by source path or ID
wp legacy-redirector get /old-page
wp legacy-redirector list --status=disabled
wp legacy-redirector update /old-page --to=/new-page

# Bulk import from a CSV file
wp legacy-redirector import /path/to/redirects.csv
```

Every command takes either a redirect ID or a source path. See **[docs/cli.md](docs/cli.md)**
for the full command list and worked recipes — bulk imports, retiring a domain, auditing
broken redirects, and migrating data created by 1.x.

### Programmatic Usage

```php
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use function Automattic\LegacyRedirector\container;

// Get the redirect manager via the helper function (recommended for third-party code)
$manager = container()->manager();

// Add a redirect to an external URL. The destination host must be allowed via
// the allowed_redirect_hosts filter, or this fails with external-url-not-allowed.
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

```bash
wp legacy-redirector create /old /new --url=site2.example.com
```

## Source Paths Are Site-Relative

A source path is always read relative to **that site's** home URL, never the domain root. On a site at `example.com/blog`, a source of `/old-page` means `example.com/blog/old-page`.

This matters wherever the home URL sits below the domain root. That includes a subdirectory multisite subsite, and equally a plain single site installed at `example.com/blog` — it is the home path that decides, not multisite. In both cases the base path is not part of the stored source:

```bash
# On a subsite at example.com/blog, these are equivalent - both store /old-page
wp legacy-redirector create /old-page /new-page --url=example.com/blog
wp legacy-redirector create https://example.com/blog/old-page /new-page --url=example.com/blog

# To redirect the real URL example.com/blog/blog/old-page, the source is /blog/old-page
wp legacy-redirector create /blog/old-page /new-page --url=example.com/blog

# On a single site installed at example.com/blog, the same rule applies without --url
wp legacy-redirector create /old-page /new-page
wp legacy-redirector create https://example.com/blog/old-page /new-page
```

Note that on such a site `example.com/old-page` is served by whatever sits at the domain root and never reaches WordPress, so only paths below the base URL can be redirected.

The Add/Edit Redirect screen shows the site's home URL next to the source field so the resolved URL is visible as you type.

## Trailing Slashes Are Ignored

A source is stored without its trailing slash, and incoming requests are canonicalized the same way, so `/old-page` and `/old-page/` are one redirect:

```bash
# Both of these create - or update - the same redirect
wp legacy-redirector create /old-page /new-page
wp legacy-redirector create /old-page/ /new-page   # rejected as a duplicate
```

Whichever form a visitor's old link carries, the redirect fires. This is deliberately independent of your permalink structure: the source is a URL from a site that no longer exists, so your own trailing-slash convention says nothing about it, and keying on a mutable setting would orphan every stored redirect the moment it changed.

Destinations are not affected. A trailing slash there is part of where the visitor lands, so `/new-page` and `/new-page/` remain distinct.

## How It Works

Redirects are stored as a custom post type (`vip-legacy-redirect`) with:

- **MD5 hash** of the source URL in `post_name` (indexed for fast lookups)
- **Original URL** in `post_title` (human-readable)
- **Destination** as either `post_parent` (internal) or `post_excerpt` (external)

The plugin intercepts 404 requests early (priority 0 on `template_redirect`) and performs a redirect if a match is found.

## Hooks and Filters

Seven filters cover query-parameter preservation, the status code, the `Cache-Control`
lifetime, the request path, the resolved destination, creation from code, and the admin
form's reachability check. Two WordPress core filters matter too: `allowed_redirect_hosts`
for external destinations, and `http_request_host_is_external` for validating internal
ones.

All of them, with examples, are in **[docs/configuration.md](docs/configuration.md)**.

## Permissions

Redirect management is gated on a single custom capability, `manage_redirects`, granted to
administrators and editors. See
**[docs/configuration.md](docs/configuration.md#permissions)** for how to grant it to
another role or user, and why read-only access is not separable.

## Abilities and MCP Clients

On WordPress 6.9 and later the plugin registers eight abilities, so MCP clients and other
agents can manage redirects through the same services, validation, and capability checks
as the admin screens and WP-CLI. Nothing is registered on earlier versions.

See **[docs/abilities.md](docs/abilities.md)** for the ability list, their read-only and
destructive annotations, how batch failures are reported, and what is deliberately left to
WP-CLI.

## Documentation

- **[docs/cli.md](docs/cli.md)** — WP-CLI commands and recipes
- **[docs/configuration.md](docs/configuration.md)** — capabilities and filters
- **[docs/abilities.md](docs/abilities.md)** — abilities and MCP clients
- **[UPGRADING.md](UPGRADING.md)** — upgrading from 1.x to 2.0
- **[CHANGELOG.md](CHANGELOG.md)** — what changed, and when
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — development setup and standards

## Support

- **Bug reports & features**: [GitHub Issues](https://github.com/Automattic/legacy-redirector/issues)
- **VIP customers**: Contact [WordPress VIP Support](https://wpvip.com/wordpress-vip-enterprise-support/)

Please use GitHub Issues only for bug reports and feature requests, not general support questions.

## Contributing

We welcome contributions! See [CONTRIBUTING.md](./CONTRIBUTING.md) for guidelines.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for the full list of changes.

## License

Licensed under `GPL-2.0-or-later`. See [LICENSE](./LICENSE) for details.
