# Contributing to FlowMonk

Thank you for your interest in contributing to FlowMonk! This document provides guidelines and instructions for contributing.

## Code of Conduct

Please be respectful and constructive in all interactions. We welcome contributors of all experience levels.

## How to Contribute

### Reporting Bugs

1. Check if the bug has already been reported in [Issues](https://github.com/alanef/fullworks-email-helpers/issues)
2. If not, create a new issue with:
   - A clear, descriptive title
   - Steps to reproduce the bug
   - Expected vs actual behavior
   - Your environment (Docker version, OS, etc.)
   - Relevant logs or error messages

### Suggesting Features

1. Check existing issues for similar suggestions
2. Create a new issue with:
   - A clear description of the feature
   - The problem it solves
   - Any implementation ideas you have

### Submitting Code

1. Fork the repository
2. Create a feature branch from `develop`:
   ```bash
   git checkout develop
   git checkout -b feature/your-feature-name
   ```
3. Make your changes
4. Test locally with Docker:
   ```bash
   docker compose up -d
   ```
5. Commit with clear messages:
   ```bash
   git commit -m "feat: Add new feature description"
   ```
6. Push to your fork and create a Pull Request against `develop`

## Development Setup

### Prerequisites

- Docker and Docker Compose
- A Listmonk instance for testing

### Local Development

```bash
# Clone your fork
git clone https://github.com/YOUR_USERNAME/fullworks-email-helpers.git
cd fullworks-email-helpers

# Copy environment config
cp .env.example .env
# Edit .env with your test Listmonk credentials

# Start with hot-reload
docker compose up -d

# View logs
docker logs -f campaign-list-builder
docker logs -f drip-controller
```

### Code Style

- PHP: Follow PSR-12 coding standards
- JavaScript: Use modern ES6+ syntax
- HTML/CSS: Use semantic HTML, follow Pico CSS conventions
- Keep functions small and focused
- Add comments for complex logic

### Commit Messages

Use conventional commit format:
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation only
- `refactor:` Code change that neither fixes a bug nor adds a feature
- `test:` Adding or updating tests
- `chore:` Maintenance tasks

### Testing

Before submitting:
1. Test all affected functionality manually
2. Check the drip-controller logs for errors:
   ```bash
   docker exec drip-controller php /app/drip-runner.php
   ```
3. Verify the web UI works on mobile and desktop

## Pull Request Process

1. Update documentation if needed
2. Ensure your code follows the style guidelines
3. Write a clear PR description explaining:
   - What changes you made
   - Why you made them
   - How to test them
4. Link any related issues
5. Wait for review - we'll respond as soon as possible

## Questions?

Feel free to open an issue for any questions about contributing.