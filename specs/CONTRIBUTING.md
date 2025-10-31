# Contributing to Eloquent Cypher

Thank you for considering contributing to Eloquent Cypher! We welcome contributions from the community and are excited to see what you'll bring to the project.

## Code of Conduct

By participating in this project, you agree to abide by our Code of Conduct: be respectful, inclusive, and constructive in all interactions.

## How to Contribute

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. When creating a bug report, include:

- A clear and descriptive title
- Steps to reproduce the issue
- Expected behavior vs actual behavior
- Your environment details (PHP version, Laravel version, Neo4j version)
- Any relevant error messages or logs

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, include:

- A clear and descriptive title
- Detailed description of the proposed functionality
- Any possible implementations you've considered
- Why this enhancement would be useful to most users

### Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Follow Test-Driven Development (TDD)**:
   - Write a failing test FIRST
   - Then write the code to make it pass
   - Tests define the public API - they are the contract
3. **Ensure all tests pass**: Run `./vendor/bin/pest`
4. **Follow coding standards**: Run `./vendor/bin/pint` to fix styling
5. **Update documentation** if you're changing functionality
6. **Write a clear PR description** explaining your changes

## Development Setup

### Prerequisites

- PHP 8.0 or higher
- Composer
- Docker (for Neo4j)
- Laravel 10.x

### Setting Up Your Development Environment

1. Clone your fork:
```bash
git clone https://github.com/your-username/eloquent-cypher.git
cd eloquent-cypher
```

2. Install dependencies:
```bash
composer install
```

3. Copy the PHPUnit configuration:
```bash
cp phpunit.xml phpunit.xml.dist
# Or create your own phpunit.xml from the existing one
```

4. Start Neo4j with Docker:
```bash
# Use port 7688 for tests (or customize as needed)
docker run -d \
  --name neo4j-test \
  -p 7688:7687 \
  -p 7475:7474 \
  -e NEO4J_AUTH=neo4j/password \
  neo4j:5-community

# For custom ports, update the connection settings in your tests
```

5. Run tests to verify setup:
```bash
./vendor/bin/pest
```

## Development Principles

### Test-Driven Development (TDD)

This project follows strict TDD principles:

1. **EVERY feature starts with a failing test** - No code is written until a test exists
2. **Tests define the PUBLIC API** - Tests describe what users will actually use
3. **Tests are IMMUTABLE** - Once written, fix the implementation, never the test
4. **Tests are the SPECIFICATION** - They define the contract with users

### Code Style

- Use PHP 8.0+ features appropriately
- Follow PSR-12 coding standards (enforced by Pint)
- Use type hints everywhere
- Avoid unnecessary docblocks - let the code speak for itself
- Keep methods small and focused
- Follow existing patterns in the codebase

### Commit Messages

- Use clear, descriptive commit messages
- Start with a verb in present tense: "Add", "Fix", "Update", "Remove"
- Keep the first line under 50 characters
- Add detailed description if needed after a blank line

Example:
```
Add support for whereJsonContains in query builder

This enables filtering by JSON column values using Neo4j's
JSON property access syntax, maintaining full compatibility
with Laravel's Eloquent API.
```

## Testing

### Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Feature/AttributeCastingTest.php

# Run with coverage
./vendor/bin/pest --coverage

# Run with filter
./vendor/bin/pest --filter="casts collection"
```

### Writing Tests

- Place feature tests in `tests/Feature/`
- Place unit tests in `tests/Unit/`
- Follow existing test patterns
- Test both success and failure cases
- Use descriptive test names that explain what's being tested

## Quality Checks

Before submitting your PR, run:

```bash
# Fix code style
./vendor/bin/pint

# Static analysis
./vendor/bin/phpstan analyze src/

# Run all tests
./vendor/bin/pest
```

## Documentation

- Update README.md if you're adding new features
- Add inline comments only for complex logic
- Update CHANGELOG.md with your changes
- Keep examples realistic and tested

## Getting Help

- Check [docs/development/](docs/development/) for technical documentation
- Review existing issues and PRs for context
- Ask questions in issue discussions
- Refer to [ARCHITECTURE.md](ARCHITECTURE.md) for design decisions

## Recognition

Contributors will be recognized in our releases and documentation. Thank you for helping make Eloquent Cypher better!

## Questions?

Feel free to open an issue for any questions about contributing. We're here to help!