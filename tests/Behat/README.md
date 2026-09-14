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

1. **CLI argument parsing** - WP-CLI's own parsing behaviour
2. **Output format verification** - Exact text users see
3. **File I/O operations** - CSV import/export with real files
4. **WordPress filter contracts** - `allowed_redirect_hosts` etc.
5. **Environment validation** - Plugin activation, command registration

## Scenarios We Keep (and Why)

| Feature | Scenario | Rationale |
|---------|----------|-----------|
| testing.feature | WP-CLI loads for your tests | Validates wp-env setup works |
| testing.feature | WP-CLI recognises plugin commands | Validates plugin activation |
| testing.feature | WP-CLI recognises wpcom-legacy-redirector commands | Validates command registration |
| create.feature | Create a redirect to a path | Smoke test for basic creation |
| create.feature | Creating a duplicate redirect fails | Tests error contract and exit code |
| list.feature | List all redirects | Smoke test for list output format |
| list.feature | Export redirects as CSV | Tests the supported CSV export path |
| validate.feature | Validate batch mode with no issues | Tests batch processing with real WordPress |
| validate.feature | Validate redirect pointing to trashed post | Tests WordPress post state integration |
| import.feature | Import redirects from a valid CSV file | Tests file I/O + bulk workflow |

**Total: ~10 key scenarios** covering the critical paths.

## What NOT to Test in Behat

These are better covered by PHPUnit unit or integration tests:

- **Individual error cases** - e.g., "redirect not found" errors
- **Domain validation logic** - e.g., source/destination matching
- **Flag combinations** - e.g., `--limit`, `--offset`, `--format`
- **Edge cases** - e.g., empty results, special characters
- **CRUD operations** - enable, disable, delete, update, get (except smoke tests)

These scenarios can execute ~100x faster in PHPUnit than in Behat.

## Adding New Behat Tests

Before adding a Behat scenario, ask:

1. **Does this test something only Behat can verify?** (CLI parsing, file I/O, filter contracts)
2. **Is this a critical happy path worth the ~15s execution cost?**
3. **Can this be tested faster with PHPUnit?**

If the answer to #1 or #2 is "no", write a PHPUnit test instead.

### Good Candidates for Behat

- A new command that needs smoke testing
- Functionality that depends on WordPress filters with external effects
- File-based operations (CSV, logs)
- Multi-command workflows

### Poor Candidates for Behat

- Error handling for invalid inputs
- Testing different output formats (--format=json/csv/table)
- Pagination behaviour
- Search/filtering logic

## Running Behat Tests

```bash
# Run all Behat tests
composer behat

# Run a specific feature
composer behat -- features/testing.feature

# Run a specific scenario by line number
composer behat -- features/create.feature:9
```

## Maintaining Behat Tests

When modifying CLI commands:

1. **Check if existing Behat tests cover the change** - if so, update them
2. **Prefer adding PHPUnit tests** for new functionality
3. **Only add Behat tests** when they provide unique coverage value
4. **Keep scenarios focused** - one assertion per scenario when possible
5. **Use Background blocks** to reduce setup duplication
