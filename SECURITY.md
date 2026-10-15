# Security Policy

TIP Tool handles mailbox credentials and the contents of your incoming mail, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Use GitHub's private reporting instead: open the repository's **Security** tab and choose **Report a vulnerability**. Include:

- what the problem is and where it is (file, endpoint, or page),
- steps to reproduce it,
- the impact you expect, and your PHP and server versions if relevant.

You can expect an acknowledgement within a few days. Fixes are released as soon as they are ready, and you will be credited in the release notes unless you prefer not to be.

## Supported versions

Only the latest release on the `main` branch receives security fixes.

## Hardening your own deployment

- Delete `setup.php` as soon as setup succeeds. It can write database credentials to disk.
- Keep `tip-config/` outside the web root and never commit it.
- Serve the site over HTTPS only.
- Make sure `.htaccess` is honoured: `/includes/` and `/logs/` should return 403 from the browser.
- Use a strong, unique admin password.
- Give the database user rights on this application's database only.
