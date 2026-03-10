# Contributing to Coinbase Payment Link Gateway for Adobe Commerce

Thank you for your interest in contributing! This guide explains how to get started.

## Prerequisites

- Adobe Commerce / Magento 2.4.7+
- PHP 8.2+
- Composer

## Getting Started

1. Fork this repository.
2. Clone your fork locally.
3. Copy the module into a Magento 2 installation:
   ```bash
   cp -r app/code/Coinbase/ <magento-root>/app/code/
   ```
4. Install dependencies:
   ```bash
   cd <magento-root>
   composer require firebase/php-jwt:^7.0
   ```
5. Enable the module:
   ```bash
   bin/magento module:enable Coinbase_PaymentLinkGateway
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

## Development Workflow

1. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
2. Make your changes.
3. Test your changes against a running Magento instance.
4. Commit your changes with [signed commits](https://docs.github.com/en/authentication/managing-commit-signature-verification/signing-commits).
5. Push your branch and open a Pull Request.

## Pull Requests

- Keep PRs focused on a single change.
- Include a clear description of what changed and why.
- Ensure all existing functionality still works.
- Update documentation if your change affects usage or configuration.

## Reporting Issues

Open a GitHub issue with:
- A clear description of the problem.
- Steps to reproduce.
- Your Magento and PHP versions.

## Security

If you discover a security vulnerability, **do not** open a public issue. Please refer to our [Security Policy](SECURITY.md).

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
