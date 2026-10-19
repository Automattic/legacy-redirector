# Keeping redirects healthy

A redirect that worked when it was added can stop working later: the post it points at is
unpublished, a page is published at its source, the site it sends people to goes away. This
page explains how the plugin catches that: when it checks, what it checks, where you see the
results, and what it cannot see.

## When redirects are checked

There are four points, from the moment a redirect is written to whenever you ask.

### When a redirect is saved

Every write goes through the same rules, whether it comes from the Add/Edit form, `create`,
`update`, `import`, or the abilities. Some findings refuse the save, because the redirect
could never work:

- the source already belongs to another redirect;
- the destination is the source itself;
- the destination post does not exist, is in the trash, is not published, or is of a type
  visitors can't view (a template or another redirect, say);
- the destination is on a host that is not in `allowed_redirect_hosts`.

Others let the save go ahead and warn, because the redirect works but deserves a look:

- the source is a path WordPress itself serves, such as `/wp-admin`;
- a published post or page already lives at the source, so the redirect lies dormant until it goes;
- the new redirect closes a loop with others.

The Add/Edit form checks earlier still. When the source field loses focus it says whether
the source is already taken (which blocks the save), reserved, or already answered by a published post or page (both warnings), and when the
destination field does, whether its host is allowed. On save, a relative destination that
resolves to no post is requested once, and a 404 refuses the save. That request fails open:
only a definite 404 blocks, never a timeout. The
[`legacy_redirector_check_destination_reachability`](configuration.md#skip-the-admin-forms-reachability-check)
filter turns it off.

`create` and `import` accept `--skip-validation` to write without the destination rules. The
duplicate-source rule still applies, because the database cannot hold two redirects for one
source. Changing only a redirect's status, such as enabling it, does not re-check its
destination; the Health column still shows what is wrong with it.

### When a destination post changes

A redirect that points at a post by ID is re-checked the moment that post is unpublished,
trashed, or permanently deleted, and again when it is published. The "Has issues" view and
the menu badge update straight away, without waiting for the background check. This covers
the commonest way a redirect breaks, and it costs one indexed lookup per change, whatever the
size of the site.

Only post-ID destinations are watched this way. A relative path that resolves to a post, a
loop, or a change to `allowed_redirect_hosts` has no event to watch, so those wait for the
background check.

### In the background

Every redirect, enabled and disabled, is checked in the background for everything its stored
details can show. Every five minutes the check spends up to 20 seconds working through the
table from where it last stopped. It starts a new pass at most once a day: a pass that
finishes within a day waits until a day after it began, and one that takes longer is followed
straight away by the next, never overlapping it. A site with a few thousand redirects is
checked daily; one with millions takes longer per pass, and the list screen says how long.
Change the budget, or turn the check off, with the
[`legacy_redirector_background_audit_seconds`](configuration.md#tune-or-turn-off-the-background-check)
filter.

The background check never requests a URL. See [Stored details and live
checks](#stored-details-and-live-checks) for why.

### When you ask

| Check | Where | Covers | Requests URLs |
|-------|-------|--------|---------------|
| **Validate all redirects** | Redirects list toolbar | Every redirect, now, instead of waiting for the background | No |
| `wp legacy-redirector validate` | WP-CLI | Every redirect, or the ones you name | No |
| `validate-redirects` ability | MCP clients | The redirects you name, or the ones already flagged | No |
| **Test** | Row action and bulk action | The redirects you pick, live | Yes: the destination, and the source |
| `wp legacy-redirector test` | WP-CLI | The redirects you name, live | Yes: the destination, and the source |
| `test-redirects` ability | MCP clients | Up to 20 named redirects, live | Yes: the destination, and the source |

The validate checks read stored details, and record what they find, as the background check
does: they are the background check, run on demand. The test checks make requests, and
record nothing, because a timeout or a throttled request is not a fact about a redirect.

## What is checked

A **problem** means the redirect is broken: visitors who reach it are sent nowhere useful,
or it will not run. A **warning** means it works but deserves a human look; nothing
automatic ever disables a redirect for a warning.

A check that needs a request can also come back **inconclusive**: the request timed out, or
the server answered with an error that is as likely to be passing as permanent. It is still
reported, but test again before treating one as broken.

| Finding | Severity | Found by |
|---------|----------|----------|
| Destination post deleted | Problem | Stored details |
| Destination post in the trash | Problem | Stored details |
| Destination post not published, or of a type visitors can't view | Problem | Stored details |
| Destination host not in `allowed_redirect_hosts` | Problem | Stored details |
| Corrupt stored data (a row that cannot be read as a redirect) | Problem | Stored details |
| Reserved WordPress path as the source | Warning | Stored details |
| Possible loop through other redirects | Warning | Stored details |
| Destination returns 404 | Problem | A request |
| Destination returns a server error | Problem, inconclusive | A request |
| Request to the destination failed | Problem, inconclusive | A request |
| Source redirects somewhere else | Problem | A request to the source |
| Source returns a 404 (or 410) without the redirect firing | Problem | A request to the source |
| Source serves its own content, so the redirect lies dormant | Warning | Stored details (a published post or page at the source), or a request to the source (anything else) |
| Request to the source failed, or the site turned it away (a 401, 403 or 429) | Problem, inconclusive | A request to the source |

Post destinations are judged by the post's own status, so a media attachment counts as
published. A relative path is resolved to a post of any registered type, including dated
permalinks, and judged the same way; a path that resolves to no post at all, such as an
archive or a page served outside WordPress, is not reported, because only a request can say
whether it responds.

The source is only requested for an enabled redirect: a disabled one is not meant to fire.

Every finding says what to do about it, alongside what is wrong. Almost nothing can be fixed
automatically: restoring a post, repointing a redirect, and allowing a host are decisions for
a person. So nothing disables or changes a redirect on its own. To switch off the redirects
with problems, use the bulk Disable action on the "Has issues" view, or `list --has-issues`
piped into `disable` (see [cli.md](cli.md#find-and-clean-up-broken-redirects)).

Redirects that the 2.0 upgrade disabled because they shared a source with a live redirect
are handled separately: the list screen has a **Duplicate sources** view and a notice until
they are settled. See [settling a disabled
duplicate](../UPGRADING.md#settling-a-disabled-duplicate).

## Where to see problems

In the admin, on the Redirects list:

- **The Health column** shows every stored-details finding for each row, worked out live
  each time the page loads, so it is always current. Hovering over a finding says what is
  wrong and what to do; screen readers announce both. "Not fully checked" means only a request
  can judge the destination; use Test. After a Test, the cell shows the live result.
- **The "Has issues" view** lists the redirects the last check flagged, so bulk actions can
  be applied straight to them. It is a snapshot: saving a redirect clears its flag until it
  is next checked, and the view is labeled with when the last full check finished.
- **The Redirects menu badge** counts the redirects flagged as problems, matching the "Has
  issues" view.
- **The line beside Validate all redirects** says when every redirect was last checked, how far a
  check in progress has got, and how long a full check takes in the background.

After a save, a notice shows any warnings for that redirect, and what to do about them.

From the command line, `list --has-issues` lists the flagged redirects, `validate` and `test`
print their findings (with what to do) as a table, CSV, JSON or YAML, and `create` and
`import` print warnings as they write.

## Stored details and live checks

Most of what can go wrong is visible in the stored data: a post's status, a host list, the
other redirects. Those checks are cheap and run everywhere. Some things only a request can
show:

- whether an external destination still responds;
- whether a relative path that is not a post (an archive, a rewrite endpoint) responds;
- whether visiting the source actually redirects, and to where.

Those run only when you ask, because a request per redirect does not scale (millions of
redirects would take days) and because, on WordPress VIP, the request helper gives up after
a second and then stops calling a slow host for a while, so a bulk run in the background
would mostly report timeouts rather than broken redirects.

## What is not caught automatically

- **Path destinations, loops, host-list changes, and posts published at a source** are
  caught by the background check, not at the moment they happen.
- **A redirect to a media item whose parent post is unpublished** is caught by the
  background check, because nothing changes on the media item itself.
- **Other content at a redirect's source**, such as an archive, a feed, a paged URL, or a
  permalink that does not end in the post's slug (`/%post_id%/`), also makes the redirect
  dormant. Only Test or `test` sees it.
- **A destination page that has gone** on another site is only found by Test or `test`.

## Adding a check

New checks belong in `RedirectAuditor`, as a new `AuditFindingType` case: every surface on
this page reads its findings from there, so they cannot disagree. Which findings refuse a
save is decided separately, in `RedirectValidator::refusal_for()`.

When adding a case, update the table on this page, and the places that describe checks to
people where they work: the list screen's "What is checked" help
(`ListScreenSetup::get_checks_help()`), the Add/Edit form's help (`FormScreenSetup`), the
`validate` command's docblock, and the `validate-redirects` ability's description (for a live
check, the `test` command's docblock and the `test-redirects` ability's description instead).
