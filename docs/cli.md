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
| `validate` | Find (and optionally disable) broken redirects |
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

`update`, `delete`, `enable`, `disable`, and `validate` take any number of them in one
call, mixing the two forms freely:

```bash
wp legacy-redirector disable /old-page 456 /another-old-page
```

## The deprecated namespace

The pre-2.0 `wp wpcom-legacy-redirector` namespace is still registered, so existing
scripts keep working. Each invocation through it prints a deprecation warning naming the
`wp legacy-redirector` equivalent, and it will be removed in a future major version.

The warning goes to STDERR, so piping `--porcelain` or `--format=csv` output through the
old namespace still produces clean data on STDOUT.

## Recipes

### Migrate redirects created by 1.x

Redirects created by 1.x will not fire under 2.0 until they have been migrated. This
happens automatically in small batches on ordinary page loads, but on a site with a large
redirect set it is better to do it in one pass:

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

The command reports how many redirects were published, how many had their source path
rewritten, how many were trashed as duplicates, and how many had their destination made
relative. It also lists any source path that two redirects now disagree about; those are
left disabled rather than deleted, so review them afterwards.

See [UPGRADING.md](../UPGRADING.md) for what each of those passes changes and why.

### Bulk import from an old CMS export

The CSV takes one redirect per row, with no header row:

```csv
/old/legacy/path,/shiny/new/path
/another-legacy-path,https://other-domain.example.com/other-path
/a-third-path,123
/staged-for-later,/new-page,disabled
```

The destination can be a path, an absolute URL, or the ID of a post on this site. The
third column is optional and accepts `enabled` or `disabled`; omitted, new redirects are
created enabled.

Preview first, then import:

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

Re-running an import is safe: by default a row whose source already has a redirect is
reported as a duplicate and skipped. To make the CSV authoritative instead — updating
destinations that have changed and creating the rest — use `--mode=upsert`:

```bash
wp legacy-redirector import redirects.csv --mode=upsert
```

Only errors are reported by default. Add `--verbose` to see every row.

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

`validate` inspects the destinations of redirects you already have, and reports the ones
that no longer go anywhere: a post that has since been deleted, trashed, or unpublished; a
relative path that does not resolve to published content; a row whose stored data is
corrupt.

```bash
wp legacy-redirector validate
```

Destinations on other domains are left alone unless you ask for them, because checking one
means an HTTP request. `--check-urls` adds that, and reports the destinations that answer
with an error. It is slow, so it suits a scheduled audit rather than an interactive check:

```bash
wp legacy-redirector validate --check-urls --format=csv > broken.csv
```

`--fix` disables everything it reports, which stops visitors being sent somewhere broken
while leaving the redirects in place to repair:

```bash
wp legacy-redirector validate --fix
```

Note that `validate` only inspects enabled redirects and stops at 1,000 by default. Widen
it explicitly for a full audit:

```bash
wp legacy-redirector validate --status=any --limit=100000 --format=count
```

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

**Restoring an export needs the header row removed**, because `import` treats every line
as data:

```bash
tail -n +2 redirects.csv | wp legacy-redirector import - --mode=upsert
```

### Act on a filtered set

`--format=ids` pipes into any of the commands that take multiple redirects:

```bash
# Delete every disabled redirect.
wp legacy-redirector list --status=disabled --format=ids \
  | xargs wp legacy-redirector delete --yes

# Disable everything pointing at a post rather than a URL.
wp legacy-redirector list --destination-type=post --format=ids \
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
`--dry-run`:

```bash
wp legacy-redirector import-from-meta --meta-key=legacy-url --start=0 --end=1000 --dry-run
```

`--skip-dupes` passes over sources that already have a redirect, which makes re-runs
cheap.

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

**Imports are deliberately paced.** `import` pauses for a second every 100 rows and
flushes caches, to keep a large import from overwhelming the database. Budget roughly a
second per 100 redirects: a 50,000-row file takes at least eight minutes.

**Destinations are validated by default.** `create` and `import` check the destination
before storing it. `--skip-validation` turns that off, which is occasionally needed when
importing redirects to content that has not been published yet, but it means a broken
destination is stored without complaint. Follow up with `validate`.

**Creating a redirect for a URL that currently works does nothing visible.** Redirects
only fire on requests that would otherwise 404, so a redirect whose source is still a
live page stays dormant until that page goes away.
