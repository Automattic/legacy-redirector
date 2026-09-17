# Contributing to WPCOM Legacy Redirector

Thank you for your interest in contributing to WPCOM Legacy Redirector! This document provides guidelines for contributing to the project.

## Requirements

- **PHP:** 8.3+
- **WordPress:** 6.8+
- **Coding Standards:** [WordPress VIP Coding Standards](https://github.com/Automattic/VIP-Coding-Standards)

## Development Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/Automattic/wpcom-legacy-redirector.git
   cd wpcom-legacy-redirector
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Start the development environment:**
   ```bash
   npx wp-env start
   ```

## Testing

### Running Tests

```bash
# Run unit tests
composer test:unit

# Run integration tests (requires wp-env)
composer test:integration

# Run integration tests (multisite)
composer test:integration-ms

# Run Behat end-to-end tests
composer test:behat

# Run all tests
composer test
```

### Code Quality

```bash
# Check PHP syntax
composer lint

# Check coding standards
composer cs

# Auto-fix coding standards issues
composer cs-fix
```

## Making Changes

### Workflow

1. **Create a feature branch from `develop`:**
   ```bash
   git checkout develop
   git pull origin develop
   git checkout -b feature/my-new-feature
   ```

2. **Make your changes** in the appropriate files

3. **Run code standards checks:**
   ```bash
   composer cs
   ```

4. **Run tests:**
   ```bash
   composer test
   ```

5. **Commit your changes:**
   ```bash
   git add .
   git commit -m "feat: description of your changes"
   ```

6. **Push and create a pull request to `develop`**

### Code Standards

- We follow [WordPress VIP Coding Standards](https://github.com/Automattic/VIP-Coding-Standards)
- Run `composer cs-fix` to auto-fix what PHPCS can fix automatically
- All public methods should have PHPDoc comments
- Use meaningful variable and function names

### Writing Tests

- All new features should include tests
- Bug fixes should include a test that fails without the fix
- Unit tests go in `tests/Unit/` and extend `MonkeyStubs` (Brain Monkey, no WordPress loaded), for domain and application logic that can be isolated
- Integration tests go in `tests/Integration/` and extend the local `TestCase`, for anything that needs real WordPress
- Behat scenarios live in `features/`, with step definitions in `tests/Behat/`. Keep them to CLI contracts and critical happy paths; they are slow, so edge cases belong in integration tests
- Coverage annotations are docblock `@covers` and `@uses` tags, not PHP attributes: the suite runs on PHPUnit 9
- Follow the Arrange-Act-Assert pattern
- Use meaningful test names: `test_redirect_to_post_id_with_validation()`

### Git Commit Messages

- Use [Conventional Commits](https://www.conventionalcommits.org/) format
- Keep the first line under 72 characters
- Use prefixes: `feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`
- Reference issues: `Fixes #123`

## Pull Request Guidelines

- Keep PRs focused (one feature/fix per PR)
- Include a clear description of what and why
- Reference related issues
- Ensure CI passes before requesting review
- Respond to feedback promptly

## Architecture Overview

The code in `src/` is arranged in three layers, and the dependencies only point inwards: `Domain` knows nothing about the other two, `Application` may use `Domain`, and `Infrastructure` may use both.

- **`Domain/`** — the model: `Redirect` (entity), the `SourceUrl` and `Destination` value objects, `RedirectCriteria`, `ValidationIssue`, and the repository interfaces. Value objects are immutable; create a new instance rather than adding a setter.
- **`Application/`** — the use cases: `RedirectManager` (create, update, status, delete), `RedirectResolver` (runtime resolution), `RedirectValidator` (rules applied before saving), `RedirectAuditor` (checking existing redirects for broken destinations), and `RedirectFetcher` (resolving an ID or source path to a redirect).
- **`Infrastructure/`** — everything WordPress-specific: `PostTypeRedirectRepository` and the `CachingRedirectRepository` decorator around it, `PostType`, `Capability`, `RedirectRequestHandler` (performs the redirect), and the three client surfaces below.

### Storage

Redirects are stored as a custom post type (`vip-legacy-redirect`):

- **`post_name`**: MD5 hash of the "from" path (indexed, so lookups stay fast at scale)
- **`post_title`**: the human-readable "from" path
- **`post_parent`**: destination post ID, when redirecting to a post on this site
- **`post_excerpt`**: destination URL or path, otherwise
- **`post_status`**: `publish` for an enabled redirect, `draft` for a disabled one

This schema is a persistence detail. Read and write it through the repositories rather than reaching for `get_post()` or `$wpdb` in a command, an ability, or an admin screen.

### Client Surfaces

Redirects can be managed from three places. All of them are presentation only: each translates its own input into calls on the application services, so that validation, capability checks, and cache invalidation behave identically whichever one you use.

- **Admin UI** (`src/Infrastructure/WordPress/Admin/`)
- **WP-CLI** (`src/Infrastructure/WordPress/Cli/`)
- **Abilities API** (`src/Infrastructure/WordPress/Abilities/`) — registered on WordPress 6.9+ so MCP clients can manage redirects

Ability names mirror the CLI verbs, so when you add or change a command, consider whether the matching ability needs the same change. Behavior that two surfaces need belongs in `Application/`, not in a command, an ability, or an admin handler.

Services are wired through the DI container (`src/Infrastructure/DI/Container.php`); prefer that over scattering `new` calls in production code. The test suite deliberately constructs services directly — see `tests/Integration/RedirectTestHelper.php`.

## Getting Help

- **GitHub Issues**: For bug reports and feature requests
- **Wiki**: [Documentation](https://github.com/Automattic/wpcom-legacy-redirector/wiki)
- **WordPress VIP Support**: For WPVIP customers

## Recognition

Contributors are recognized in:
- [CHANGELOG.md](./CHANGELOG.md) for significant contributions
- GitHub contributors list

Thank you for contributing to WPCOM Legacy Redirector!
