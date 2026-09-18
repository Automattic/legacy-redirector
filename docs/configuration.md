# Configuration

## Permissions

All redirect management — the admin screens, the WP-CLI commands, and the abilities — is
gated on a single custom capability, `manage_redirects`.

The plugin grants it to the **administrator** and **editor** roles on the first admin
request after installation. The grant is version-gated, so it runs once rather than on
every request, and it is written to the roles in the database — which means deactivating
the plugin leaves it in place. Remove it explicitly if you need it gone.

Because the grant happens on `admin_init`, a site that is only ever driven by WP-CLI will
not have the capability on its roles until someone loads an admin page. That does not stop
you working: WP-CLI is trusted by virtue of being WP-CLI and does not check the capability
at all.

### Granting it to another role

One `add_cap()` call is the whole switch. Every primitive capability on the redirect post
type maps to `manage_redirects`, so there is nothing else to grant:

```php
$role = get_role( 'shop_manager' );

if ( $role instanceof WP_Role ) {
	$role->add_cap( 'manage_redirects' );
}
```

On WordPress VIP, use the platform helper instead, which handles the role change safely
across the fleet:

```php
wpcom_vip_add_role_caps( 'shop_manager', 'manage_redirects' );
```

Either call only needs to run once, not on every page load. Role changes are persisted to
the database, so the usual pattern is to run it from a one-off migration, a WP-CLI
command, or a plugin activation hook rather than from a theme's `functions.php`.

To grant it to a single user rather than a role:

```php
$user = get_user_by( 'email', 'someone@example.com' );

if ( $user instanceof WP_User ) {
	$user->add_cap( 'manage_redirects' );
}
```

### Revoking it

```php
get_role( 'editor' )->remove_cap( 'manage_redirects' );
// On VIP:
wpcom_vip_remove_role_caps( 'editor', 'manage_redirects' );
```

### Read-only access is not separable

There is no view-only capability. `manage_redirects` covers reading the redirect list as
well as changing it, and the read-only WP-CLI commands and abilities require it too.

This is deliberate: the redirect list is a map of a site's old and new URL structure, and
includes destinations that may not be published yet. Authors and contributors are not given
access to it, and the post type is registered so that WordPress's auto-generated "Add New"
submenu never appears for users who cannot manage redirects.

## Filters

All of this plugin's filters use the `legacy_redirector_` prefix. The four that shipped
before 2.0 were prefixed `wpcom_legacy_redirector_`; those names still work and emit a
deprecation notice naming the replacement. Where a callback is attached to both names, the
current name wins. [UPGRADING.md](../UPGRADING.md) has the full mapping.

| Filter | Purpose |
|--------|---------|
| `legacy_redirector_request_path` | Alter the request path before lookup |
| `legacy_redirector_destination_url` | Alter the resolved destination before redirecting |
| `legacy_redirector_redirect_status` | Change the HTTP status code |
| `legacy_redirector_redirect_max_age` | Change the `Cache-Control` lifetime |
| `legacy_redirector_preserve_query_params` | Keep named query parameters during lookup |
| `legacy_redirector_allow_insert` | Allow creation from a context with no capable user |
| `legacy_redirector_check_destination_reachability` | Skip the admin form's HTTP check |

### Preserve query parameters

Query parameters are stripped during lookup, so `/old-page?utm_source=news` matches the
stored `/old-page`. To carry named parameters through to the destination:

```php
add_filter( 'legacy_redirector_preserve_query_params', function( $params, $url ) {
	return array( 'utm_source', 'utm_medium', 'utm_campaign' );
}, 10, 2 );
```

### Change the status code

The default is 301:

```php
add_filter( 'legacy_redirector_redirect_status', function( $status, $url ) {
	return 302; // Temporary redirect.
}, 10, 2 );
```

### Change the cache lifetime

Redirect responses carry a `Cache-Control: max-age` header so browsers do not cache them
indefinitely. The default is one day for permanent redirects and one minute otherwise:

```php
add_filter( 'legacy_redirector_redirect_max_age', function( $max_age, $url, $status ) {
	return HOUR_IN_SECONDS;
}, 10, 3 );
```

Return `0` to suppress the header entirely, for example where an edge cache manages
redirect caching instead.

### Alter the request path

The path is still percent-encoded at this point, because decoding happens during lookup:

```php
add_filter( 'legacy_redirector_request_path', function( $path ) {
	return strtolower( $path ); // Case-insensitive matching.
} );
```

### Alter the destination

The counterpart to `legacy_redirector_request_path`: change the resolved destination
before the redirect is sent. Returning an empty string cancels the redirect.

This is mainly useful where a path suffix is stripped for lookup and needs re-adding to
the destination, such as the legacy `/amp/` paired URL structure:

```php
// Match /old-path/amp against the stored /old-path redirect, then re-append /amp.
add_filter( 'legacy_redirector_request_path', function( $path ) {
	return preg_replace( '#/amp/?$#', '', $path );
} );

add_filter( 'legacy_redirector_destination_url', function( $destination, $path, $url ) {
	return preg_match( '#/amp/?$#', $url ) ? trailingslashit( $destination ) . 'amp/' : $destination;
}, 10, 3 );
```

If your site uses the AMP plugin's default query parameter structure (`?amp=1`) rather
than the path suffix, you do not need this — use
`legacy_redirector_preserve_query_params` with `'amp'` instead.

### Allow creation with no capable user

WP-CLI is always allowed, and so is any user with `manage_redirects`, wherever the request
arrives from. Code running where neither is true — an unauthenticated front-end request,
for instance — is blocked unless this filter opts in:

```php
add_filter( 'legacy_redirector_allow_insert', function( $allow ) {
	return true;
} );
```

Scope it as tightly as you can. Returning `true` unconditionally lets any front-end code
path create redirects.

### Skip the admin form's reachability check

When you save a redirect in the admin, the form makes an HTTP request to confirm a
relative destination is not served as a 404. That is the right default, but it is wrong
for a site whose valid destinations legitimately 404 for an anonymous visitor —
members-only content, geo-gated pages, or content staged ahead of launch:

```php
add_filter( 'legacy_redirector_check_destination_reachability', function( $check ) {
	return false;
} );
```

WP-CLI and the abilities are unaffected; this only governs the admin form.

## External destinations

A redirect to another domain only works if WordPress is willing to send visitors there.
Allow the host with core's `allowed_redirect_hosts` filter:

```php
add_filter( 'allowed_redirect_hosts', function( $hosts ) {
	$hosts[] = 'other-domain.example.com';
	$hosts[] = 'www.other-domain.example.com';

	return $hosts;
} );
```

Note that `www.` is a different host and needs listing separately.

Creating or updating a redirect to a host the site does not allow is an error naming the
domain, so you find out at the point of creation. A redirect that was already stored
before the host was removed from the list leaves the original 404 in place rather than
sending visitors anywhere unexpected.

`wp legacy-redirector find-domains` lists every domain your existing destinations point
at, which is the quickest way to audit the list you need.

## Validating destinations on internal hosts

Destination validation — the admin "Validate" action and `wp legacy-redirector validate
--check-urls` — uses WordPress's safe HTTP functions, which refuse to request loopback,
private, and reserved IP addresses. The site's own host is always allowed.

If your redirects legitimately point at other internal hosts, such as an intranet or a
staging network, allow them with core's filter:

```php
add_filter( 'http_request_host_is_external', function( $external, $host ) {
	return 'internal.example.test' === $host ? true : $external;
}, 10, 2 );
```

Even with this filter, safe requests only use ports 80, 443, and 8080, plus the site's own
port. Destinations on other ports report as failed in validation; the redirects themselves
still work.
