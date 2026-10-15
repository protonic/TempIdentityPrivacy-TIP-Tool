# Contributing to TIP Tool

Thanks for taking an interest. TIP Tool is a small, self-hosted PHP project, and contributions of any size are welcome: bug reports, documentation fixes, screenshots, translations, and code.

## Ways to help

- **Report a bug** or a confusing setup step using the issue templates.
- **Improve the docs**, especially host-specific setup notes (cPanel, other shared hosts, Docker, IIS).
- **Test on your stack** and tell us what broke: PHP version, hosting provider, IMAP provider.
- **Pick up an issue** labelled `good first issue` or `help wanted`.

## Running it locally

You need PHP 8.1+ with `pdo_mysql`, `imap`, `openssl`, `mbstring`, MySQL or MariaDB, and Apache (XAMPP or any LAMP stack works).

1. Clone the repository into your web root.
2. Create an empty database and open `setup.php` in your browser. It imports the schema, creates your admin account, and writes the config files one level above the web root.
3. Delete `setup.php` when it finishes.

See the [README](README.md) for the full walkthrough.

## Pull requests

- Keep each pull request focused on one change.
- Describe what changed and why, and how you tested it.
- Run `php -l` on every PHP file you touched.
- Match the existing style: plain PHP functions and classes, prepared statements for every query, and output escaped with `htmlspecialchars`.
- Do not commit credentials, personal data, or deployment-specific files. `tip-config/` lives outside the repository for that reason.
- For UI changes, include a before and after screenshot.

## Security

Please do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contribution is licensed under the [MIT License](LICENSE).
