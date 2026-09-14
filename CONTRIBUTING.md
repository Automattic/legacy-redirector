# Contributing to WPCOM Legacy Redirector

Thank you for your interest in contributing to WPCOM Legacy Redirector! This document provides guidelines for contributing to the project.

## Requirements

- **PHP:** 8.2+
- **WordPress:** 6.4+
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
   wp-env start
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
composer cbf
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
- Run `composer cbf` to auto-fix PHP issues
- All public methods should have PHPDoc comments
- Use meaningful variable and function names

### Writing Tests

- All new features should include tests
- Bug fixes should include a test that fails without the fix
- Unit tests go in `tests/Unit/`
- Integration tests go in `tests/Integration/`
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

The plugin uses a custom post type (`vip-legacy-redirect`) to store redirects:

- **`post_name`**: MD5 hash of the "from" URL (for fast indexed lookups)
- **`post_title`**: Human-readable "from" URL
- **`post_parent`**: Destination post ID (for internal redirects)
- **`post_excerpt`**: Destination URL (for external redirects)

Key classes:

- `WPCOM_Legacy_Redirector`: Main plugin class with redirect insertion and validation
- `Lookup`: Handles redirect resolution and caching
- `Post_Type`: Registers the custom post type
- `Capability`: Manages the `manage_redirects` capability
- `List_Redirects`: Admin list table customisation
- `WPCOM_Legacy_Redirector_CLI`: WP-CLI commands
- `WPCOM_Legacy_Redirector_UI`: Admin interface for adding redirects

### Client Surfaces

Redirects can be managed from three places, all of which must go through the application services so that validation, capabilities, and cache invalidation stay consistent:

- **Admin UI** (`src/Infrastructure/WordPress/Admin/`)
- **WP-CLI** (`src/Infrastructure/WordPress/Cli/`)
- **Abilities API** (`src/Infrastructure/WordPress/Abilities/`) — registered on WordPress 6.9+ so MCP clients can manage redirects. Ability names mirror the CLI verbs; when you add or change a command, consider whether the matching ability needs the same change.

## Getting Help

- **GitHub Issues**: For bug reports and feature requests
- **Wiki**: [Documentation](https://github.com/Automattic/wpcom-legacy-redirector/wiki)
- **WordPress VIP Support**: For WPVIP customers

## Recognition

Contributors are recognised in:
- [CHANGELOG.md](./CHANGELOG.md) for significant contributions
- GitHub contributors list

Thank you for contributing to WPCOM Legacy Redirector!
