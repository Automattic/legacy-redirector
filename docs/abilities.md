# Abilities and MCP clients

On WordPress 6.9 and later, the plugin registers its redirect operations with the
Abilities API, so MCP clients and other agents can discover and call them. Each ability
goes through the same services as the admin screens
and WP-CLI, which means the same validation, the same capability check, and the same cache
invalidation.

Nothing is registered on WordPress 6.8. The hooks the plugin uses simply never fire, and
core only fires them at all once something asks the registry for abilities, so front-end
requests pay nothing for this.

## What gets registered

One ability category, `legacy-redirects`, and eight abilities within it.

The annotations matter: MCP clients read them to decide what to confirm with a user before
calling. `readonly` means the ability cannot change anything, `destructive` means data is
lost, and `idempotent` means calling twice with the same input has the same effect as
calling once.

| Ability | What it does | Read-only | Destructive | Idempotent |
|---------|--------------|-----------|-------------|------------|
| `legacy-redirector/create-redirect` | Create one redirect | No | No | No |
| `legacy-redirector/get-redirect` | Get one redirect, by ID or source path | Yes | No | Yes |
| `legacy-redirector/list-redirects` | List redirects, with filters and paging | Yes | No | Yes |
| `legacy-redirector/update-redirect` | Change the destination, and optionally the status, of one or more redirects | No | No | Yes |
| `legacy-redirector/set-redirect-status` | Enable or disable one or more redirects | No | No | Yes |
| `legacy-redirector/delete-redirect` | Delete one or more redirects | No | **Yes** | Yes |
| `legacy-redirector/validate-redirects` | Report redirects whose destinations no longer resolve | Yes | No | Yes |
| `legacy-redirector/find-redirect-domains` | List the domains destinations point at | Yes | No | Yes |

`create-redirect` is not idempotent because a second call with the same source is a
duplicate, and fails. Use `update-redirect` when you mean "make it so" rather than "add a
new one".

Disabling a redirect is not destructive: the redirect and its destination are kept, it
just stops being served to visitors. Only `delete-redirect` loses data.

## Connecting a client

The plugin registers abilities. It does not itself speak MCP — bridging the Abilities API
to an MCP client is the job of a separate MCP server layer, which is not part of this
plugin. What this plugin guarantees is that the abilities are registered, described, and
exposed over the REST API (`show_in_rest` is set on all eight), so anything that reads the
ability registry will find them.

Two things to get right on the WordPress side:

**The acting user needs `manage_redirects`.** An ability call runs as a WordPress user, and
every one of these abilities checks the capability. A client authenticating as a user
without it gets a permission error rather than an empty result. See
[configuration.md](configuration.md) for how to grant it.

**Read-only is still gated.** `get-redirect`, `list-redirects`, `validate-redirects`, and
`find-redirect-domains` all require `manage_redirects` too. There is no anonymous or
subscriber-level read access to the redirect map.

### Checking the abilities are registered

`wp_get_ability()` returns a `WP_Ability` for a registered name, or `null`:

```bash
wp eval '
foreach ( array( "create-redirect", "get-redirect", "list-redirects", "update-redirect", "set-redirect-status", "delete-redirect", "validate-redirects", "find-redirect-domains" ) as $name ) {
	$ability = wp_get_ability( "legacy-redirector/$name" );
	echo $name, ": ", ( null === $ability ? "missing" : "ok" ), PHP_EOL;
}'
```

Eight `ok` lines means everything is in place. On WordPress 6.8 the function does not
exist, which is expected rather than a fault.

## Batch operations report per-redirect failures

`update-redirect`, `set-redirect-status`, and `delete-redirect` take a list of redirects,
identified by ID or source path in any mix. They do not fail the whole call when one entry
is bad. Instead they return a count of what succeeded and an array of what did not, with a
reason for each:

```json
{
  "updated": 2,
  "failed": [
    { "redirect": "/never-existed", "reason": "No redirect found for /never-existed." }
  ]
}
```

The count is named `updated` by `update-redirect` and `set-redirect-status`, and `deleted`
by `delete-redirect`. `failed` is the same shape on all three.

An agent should read `failed` rather than inferring success from a non-error response: a
call that changed nothing at all still returns normally.

## What is deliberately not an ability

There is no ability for importing from a CSV file, importing from post meta, or running
the 1.x data migration. Those are bulk operations that belong on the command line, where
they can be previewed with `--dry-run`, paced, and watched. Use
[WP-CLI](cli.md) for them.

There is also no ability that creates many redirects in one call. `create-redirect` takes
one. A client that needs a hundred should call it a hundred times and handle each result,
or hand the job to `wp legacy-redirector import`.
