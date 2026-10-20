# Behat Test Strategy

## Why Behat Tests Exist

Behat tests verify the **CLI contract** - the actual user experience when running WP-CLI commands.
They are slower than PHPUnit (~2s per scenario) because they run against a real WordPress instance via wp-env.

Unit tests mock everything; Behat tests are full end-to-end. There's value in having a small number
of Behat tests that verify the complete workflow works, while keeping the majority of test coverage
in faster PHPUnit tests.

## Testing Pyramid for CLI Commands

```
        /\
       /  \     Few Behat tests (smoke tests, CLI contract)
      /----\
     /      \   More PHPUnit integration tests (scenarios)
    /--------\
   /          \ Many unit tests (command logic, domain)
  --------------
```

- **Unit tests**: Test individual classes in isolation with mocked dependencies
- **Integration tests**: Test commands with real WordPress database, but invoke programmatically
- **Behat tests**: Test full CLI execution via wp-env shell

## What Behat Tests Should Cover

Behat tests provide unique value for:

1. **CLI argument parsing** - WP-CLI's own parsing behavior
2. **Output format verification** - Exact text users see
3. **File I/O operations** - CSV import/export with real files
4. **WordPress filter contracts** - `allowed_redirect_hosts` etc.
5. **Environment validation** - Plugin activation, command registration

## What the Features Cover

Each file in `features/` covers one command or flow, with a comment above each scenario saying what it proves: a smoke test of the happy path, a CLI contract (exit codes, STDERR, files), or an end-to-end check over real HTTP (`redirect.feature`, `upgrade-from-1.3.feature`). Keep each file to a few scenarios.

PHPUnit stubs `WP_CLI`, `format_items()` and `write_csv()`, so only Behat sees real exit codes, real output formats, real files and STDIN. Those belong here; logic branches belong in PHPUnit.

## Writing Steps

- `When I run` fails the step if the command exits non-zero. Use `When I try` for a command that may fail, then assert `the return code should be N`.
- For a front-end redirect, assert `Then the response should be a 301 redirect to "/path"`. It checks the status line, the `Location` header and `X-Redirect-By`, so a redirect elsewhere or a core canonical redirect cannot pass.
- To pipe text into a command, use ``When I run `wp legacy-redirector import -` with STDIN:`` followed by a PyString. Saved variables are replaced in it, so one command's saved output can be fed to the next.
- The site is reset before the first scenario and after every scenario. Behat runs against the wp-env dev site, so a run deletes its posts and every user except ID 1.
- The reset also puts options back, so a feature behaves the same run alone as in the full suite: permalinks are set to `/%postname%/` (the plugin only redirects 404s, and with plain permalinks an unmatched path is not a 404), the plugin's upgrade and audit state is deleted, and its data version is set to current. A scenario that needs other permalinks, or a site still to upgrade, sets that up itself.

## What NOT to Test in Behat

These are better covered by PHPUnit unit or integration tests:

- **Individual error cases** - e.g., "redirect not found" errors
- **Domain validation logic** - e.g., source/destination matching
- **Flag combinations** - e.g., `--limit`, `--offset`
- **Edge cases** - e.g., empty results, special characters
- **CRUD operations** - enable, disable, delete, update, get (except smoke tests)

These scenarios can execute ~100x faster in PHPUnit than in Behat.

## Adding New Behat Tests

Before adding a Behat scenario, ask:

1. **Does this test something only Behat can verify?** (CLI parsing, file I/O, filter contracts)
2. **Is this a critical happy path worth the ~2s execution cost?**
3. **Can this be tested faster with PHPUnit?**

If the answer to #1 or #2 is "no", write a PHPUnit test instead.

### Good Candidates for Behat

- A new command that needs smoke testing
- Functionality that depends on WordPress filters with external effects
- File-based operations (CSV, logs)
- Multi-command workflows

### Poor Candidates for Behat

- Error handling for invalid inputs
- Pagination behavior
- Search/filtering logic

## Running Behat Tests

```bash
# Run all Behat tests
composer test:behat

# Run a specific feature
composer test:behat -- features/testing.feature

# Run a specific scenario by line number
composer test:behat -- features/create.feature:9
```

## Maintaining Behat Tests

When modifying CLI commands:

1. **Check if existing Behat tests cover the change** - if so, update them
2. **Prefer adding PHPUnit tests** for new functionality
3. **Only add Behat tests** when they provide unique coverage value
4. **Keep scenarios focused** - one assertion per scenario when possible
5. **Use Background blocks** to reduce setup duplication
