# WP-CLI

Every command lives under `wp legacy-redirector`. For the full flag reference on any of
them, run `wp help legacy-redirector <command>` — this page covers the things `wp help`
cannot tell you: which command to reach for, and how to string them together.

## Command index

| Command | What it does |
|---------|--------------|
| `create` | Add a single redirect |
| `get` | Show a single redirect |
| `list` | List, filter, and export redirects |
| `update` | Change the destination and/or status of one or more redirects |
| `delete` | Delete one or more redirects |
| `enable` / `disable` | Start or stop serving one or more redirects |
| `validate` | Check redirects' stored details for problems, and record what is found |
| `test` | Check redirects live: whether the destination responds and the source redirects |
| `import` | Bulk import from a CSV file or STDIN |
| `import-from-meta` | Create redirects from a post meta field |
| `find-domains` | List the domains destinations point at |
| `migrate` | Bring 1.x redirect data up to the 2.0 storage format |

## Redirects are addressed by ID or by source path

Every command that takes an existing redirect accepts either form and works out which it
has been given, so you rarely need to look an ID up:

```bash
wp legacy-redirector get /old-page
wp legacy-redirector get 123
```

`update`, `delete`, `enable`, `disable`, `validate`, and `test` take any number of them
in one call, mixing the two forms freely:

```bash
wp legacy-redirector disable /old-page 456 /another-old-page
```

Any redirect named that is not found, or cannot be acted on, gets a warning naming it, and
the command exits with status 1 once the rest are done; `validate` and `test` still print
what they found for the others. A single redirect that fails gets one error instead.
`delete` looks the redirects up before asking for confirmation, and names the redirect it
is about to delete when there is one.

## The deprecated namespace

The pre-2.0 `wp wpcom-legacy-redirector` namespace is still registered for the commands
that existed before 2.0, so existing scripts keep reaching them:

- `find-domains` and `import-from-meta` run as before, with a deprecation warning naming
  the `wp legacy-redirector` equivalent. The warning goes to STDERR, so `--format`
  output piped through the old namespace stays clean on STDOUT. Note that
  `import-from-meta` flags are now kebab-case.
- `insert-redirect`, `import-from-csv`, and `export-to-csv` were removed in 2.0. Calling
  one fails with an error that names its replacement.

Commands added in 2.0, such as `create` and `list`, are only available under
`wp legacy-redirector`. The old namespace will be removed in a future major version.

## Recipes

### Migrate redirects created by 1.x

Redirects created by 1.x will not fire under 2.0 until they have been migrated. This
happens automatically in small batches on ordinary page loads (never on WP-CLI commands), but on a site with a large
redirect set it is better to do it in one pass. Either form walks the entire redirect
set, so on a site with millions of redirects expect the dry run to take minutes:

```bash
# See what will change, without writing anything.
wp legacy-redirector migrate --dry-run

# Do it.
wp legacy-redirector migrate
```

On a network, run it per site:

```bash
wp site list --field=url | xargs -I % wp --url=% legacy-redirector migrate
```

While it runs, the command prints a progress line for each percent of the set it gets
through, with the time elapsed and an estimate of the time left. If an earlier run was
interrupted, it says so and carries on from where that run stopped.

At the end it reports how many redirects were changed, how many needed no change, how
many were left alone because someone edited them after the upgrade began, and how many
could not be written. It then breaks the changes down: published, source path
rewritten, trashed as duplicates, destination made relative.

If any redirect could not be written, the command lists each one with the database's
reason and exits with an error. Those redirects are left exactly as they were, and are
remembered: once the cause is fixed, run `wp legacy-redirector migrate` again and it
retries just those, without walking the rest of the set again. That works even after the
migration has otherwise completed. A database error partway through, or an interrupted
run, is also safe to rerun: the command carries on from the batch it was working on.

Where two redirects end up with the same source but different destinations, for example
`/old` and `/old/`, only one can answer. The one already at that source stays live, and
the other is disabled rather than deleted, for you to decide. The run lists the first 20,
marks any that never fired under 1.x, and says how to settle each. To see every one,
whenever you like, list the duplicate sources:

```bash
wp legacy-redirector list --duplicates
wp legacy-redirector list --duplicates --format=csv > duplicates.csv
```

Each row shows the disabled redirect, in the spelling 1.x stored, beside the live redirect
with the same source, both with their destinations. `never_fired` is `yes` where no browser
could ever have requested the disabled spelling, so disabling it changed nothing for
visitors. For each one, decide which destination is right:

```bash
# Keep the live redirect's destination
wp legacy-redirector delete <disabled ID>

# Keep the disabled one's destination
wp legacy-redirector update <live ID> --to=<destination>
wp legacy-redirector delete <disabled ID>
```

Use the IDs: a source path the two share is refused, since it can't say which one you
mean. A disabled duplicate can't be enabled or edited in place while the live redirect has
its source. Once it's deleted or trashed, it drops off the list. The admin's Duplicate
sources view on the Redirects screen shows the same list, with the same choices.

See [UPGRADING.md](../UPGRADING.md) for what each of those passes changes and why.

### Bulk import from an old CMS export

The CSV takes one redirect per row. A header row is skipped only when its first column is
`from`, as in the export below; any other first row is read as a redirect:

```csv
/old/legacy/path,/shiny/new/path
/another-legacy-path,https://other-domain.example.com/other-path
/a-third-path,123
/staged-for-later,/new-page,disabled
```

The destination can be a path, an absolute URL, or the ID of a post on this site. The
third column is optional and accepts `enabled` or `disabled`; omitted, new redirects are
created enabled. Any other value is an error for that row, so a typo cannot quietly put a
redirect live.

Preview first, then import. The preview validates each row as the import would, so a row
the import will refuse is shown as an error rather than as one it would create:

```bash
wp legacy-redirector import redirects.csv --dry-run
wp legacy-redirector import redirects.csv
```

Where the export needs reshaping, transform it on the way in rather than writing an
intermediate file — `-` reads from STDIN:

```bash
# Old export is "id,source,target,notes"; we want columns 2 and 3.
cut -d, -f2,3 old-export.csv | wp legacy-redirector import -
```

Re-running an import is safe. In the default create mode, a row whose source already has
a redirect with the same destination and status is skipped, so re-importing an unchanged
CSV changes nothing. A row whose source already redirects somewhere else, or with a
different status, is an error that suggests `--mode=upsert`, so a corrected CSV is never
silently left unapplied. Destinations are compared in their stored form, so
`https://your-site.example/b` and `/b` are the same destination. To make the CSV
authoritative instead — updating destinations that have changed and creating the rest —
use `--mode=upsert`:

```bash
wp legacy-redirector import redirects.csv --mode=upsert
```

Only errors are reported by default. Add `--verbose` to see every row.

A live import exits with status 1 if any row fails, after importing the rows that
succeeded, and names how many failed; a clean run, including one where every row was
skipped as unchanged, exits 0. A dry run fails only when every row fails.

### Retire a domain

`find-domains` lists what your destinations actually point at, which is the quickest way
to spot a domain you no longer own:

```bash
wp legacy-redirector find-domains
```

`list --search` matches source paths, not destinations, so repointing everything aimed at
one domain means exporting and filtering:

```bash
wp legacy-redirector list --limit=100000 --fields=ID,to --format=csv \
  | grep 'old-brand\.example\.com' \
  | cut -d, -f1 \
  | xargs wp legacy-redirector update --to=/new-home
```

Remember that an external destination only works if its host is allowed via the
`allowed_redirect_hosts` filter. Creating or updating a redirect to a host the site does
not allow is an error naming the domain. See [configuration.md](configuration.md).

### Find and clean up broken redirects

For everything the plugin checks, and when and where, see
[redirect-health.md](redirect-health.md). This section covers the command line, where two
commands do the checking: `validate` reads each redirect's stored details, and `test` tries
redirects live.

`validate` reports the redirects that no longer go anywhere: a destination post that has
since been deleted, trashed, or unpublished; a relative path whose post has gone the same
way; an external destination whose host is no longer in `allowed_redirect_hosts`; a row
whose stored data is corrupt. It also warns about possible loops (a destination that is
itself another redirect's source, with the hops leading back to where they started) and
about sources on a path WordPress itself serves, such as `/wp-admin`. `create` and `import`
give the same warnings as they write.

With no redirects named, `validate` checks every redirect, enabled and disabled: the same
check the site runs in the background, run now. It requests nothing, so it is safe at any
size, and on a very large site it is the way to run a full check off-peak. It records what
it finds, so the admin "Has issues" view and the menu badge update, and `list --has-issues`
lists the redirects it flagged:

```bash
wp legacy-redirector validate
wp legacy-redirector list --has-issues
```

Name redirects to see what is wrong with each, and what to do about it. This also updates
their flags, so re-checking one after a fix that WordPress does not announce, such as a
host allowed in code, takes it off the list:

```bash
wp legacy-redirector validate /old-page 123
wp legacy-redirector list --has-issues=problem --limit=100000 --format=ids | xargs wp legacy-redirector validate
```

`test` checks named redirects live: it requests the destination where only a request can
judge it (a URL, or a path that resolves to no post), and requests the source to see
whether visiting it redirects there. It records nothing. `--only=destination` or
`--only=source` runs one of the two. To check whether every external destination still
responds:

```bash
wp legacy-redirector test /old-page
wp legacy-redirector list --destination-type=url --limit=100000 --format=ids | xargs wp legacy-redirector test --only=destination
```

A request that cannot get an answer (a timeout, a refused connection), or gets a server
error, is reported as inconclusive: the host may simply be slow or briefly down. On
WordPress VIP the request helper also stops calling a host for a short while after a few
slow responses, so one slow host can fail every check to it in a run. Test again before
treating one of these as broken.

Neither command changes a redirect. Almost no problem has a fix the plugin can safely apply
(restoring a post, repointing a redirect, and allowing a host are decisions for a person),
so each finding says what to do instead. To switch off the redirects with problems, do it
explicitly:

```bash
wp legacy-redirector list --has-issues=problem --limit=100000 --format=ids | xargs wp legacy-redirector disable
```

A disabled redirect is still checked, so it stays on the list until it is repaired or
deleted.

### Export and back up

```bash
# Everything, in the same shape import expects.
wp legacy-redirector list --limit=100000 --fields=from,to,status --format=csv > redirects.csv
```

`--limit` defaults to 100, so an export without it gives you the first hundred rows.
`--format=table` warns you when it has truncated the list; the machine-readable formats do
not, so an export is the one place this bites. Pass a number larger than your redirect set
— `wp legacy-redirector list --format=count` tells you what that is, and reports the true
total regardless of `--limit`.

Restore it as is; `import` skips the header row:

```bash
wp legacy-redirector import redirects.csv --mode=upsert
```

### Act on a filtered set

`--format=ids` pipes into any of the commands that take multiple redirects. As with an
export, pass a `--limit` larger than the set, or only the first hundred are acted on:

```bash
# Delete every disabled redirect.
wp legacy-redirector list --status=disabled --limit=100000 --format=ids \
  | xargs wp legacy-redirector delete --yes

# Disable everything pointing at a post rather than a URL.
wp legacy-redirector list --destination-type=post --limit=100000 --format=ids \
  | xargs wp legacy-redirector disable
```

### Create redirects from a post meta field

Where a migration left the old URL on the post it now lives at, `import-from-meta` turns
those into redirects. The meta value is the source; the post holding it is the
destination:

```bash
wp legacy-redirector import-from-meta --meta-key=legacy-url
```

Work through a large set in slices with `--start` and `--end`, and preview with
`--dry-run`. A dry run checks each row as the import would, so a source that already has
a redirect, or a destination post that is not published, is reported rather than promised:

```bash
wp legacy-redirector import-from-meta --meta-key=legacy-url --start=0 --end=1000 --dry-run
```

`--skip-dupes` passes over sources that already have a redirect, which makes re-runs
cheap. Without it, each source that already has a redirect is a failed row. A live run
with any failed row exits with status 1 after importing the rest, naming how many failed.

### Capture a new redirect's ID

`--porcelain` prints just the ID, for scripting:

```bash
id=$(wp legacy-redirector create /old-page /new-page --porcelain)
wp legacy-redirector disable "$id"
```

### Work on one site of a network

Everything takes `--url`:

```bash
wp legacy-redirector create /old-page /new-page --url=site2.example.com
wp legacy-redirector list --format=count --url=site2.example.com
```

Note that a source path is read relative to *that site's* home URL. On a subsite at
`example.com/blog`, a source of `/old-page` means `example.com/blog/old-page`, and the
`/blog` prefix is not part of the stored source. The same applies to a plain single site
installed below the domain root. See the README section on site-relative source paths.

## Things worth knowing

**Machine-readable formats print only the data.** With `--format=json`, `csv`, `yaml`,
`ids`, or `count`, STDOUT carries the structured value and nothing else: an empty JSON
result is `[]`, and an empty count is `0`. Headings, summaries, and "nothing found"
sentences are for the table format; warnings and errors go to STDERR whatever the format.

**Imports are deliberately paced.** `import` pauses for a second every 100 rows and
flushes caches, to keep a large import from overwhelming the database. Budget roughly a
second per 100 redirects: a 50,000-row file takes at least eight minutes.

**Destinations are validated by default.** `create` and `import` check the destination
before storing it. `--skip-validation` turns that off, which is occasionally needed when
importing redirects to content that has not been published yet, but it means a broken
destination is stored without complaint. Follow up with `validate`, which names what to do.

**Creating a redirect for a URL that currently works does nothing visible.** Redirects
only fire on requests that would otherwise 404, so a redirect whose source is still a
live page stays dormant until that page goes away.
