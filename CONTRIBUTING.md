# Contributing to xAPI Statement Monitor

Thank you for your interest in contributing! This plugin is a passive diagnostic tool for WordPress sites running LearnDash with Tin Canny. Contributions of all kinds are welcome — bug reports, feature ideas, code, and documentation improvements.

---

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). By participating, you agree to uphold a welcoming and respectful environment for everyone.

---

## Reporting Bugs

Use the **[Bug Report issue template](.github/ISSUE_TEMPLATE/bug_report.md)** on GitHub Issues.

Please include:
- Exact steps to reproduce the problem
- What you expected to happen vs. what actually happened
- The output from the **System Health** tab in the xAPI Monitor dashboard
- Version numbers for WordPress, PHP, LearnDash, and Tin Canny

The more context you provide, the faster it can be investigated.

---

## Requesting Features

Use the **[Feature Request issue template](.github/ISSUE_TEMPLATE/feature_request.md)** on GitHub Issues.

Good feature requests explain:
- The problem you're trying to solve (not just the solution)
- Who would benefit from this feature
- Whether you'd be willing to help implement it

---

## Submitting Pull Requests

### 1. Fork and branch

```bash
git clone https://github.com/barryschoedel/xapi-statement-monitor.git
cd xapi-statement-monitor
git checkout -b feature/your-feature-name
```

Use descriptive branch names:
- `fix/endpoint-health-loopback`
- `feature/grassblade-support`
- `docs/improve-faq`

### 2. Make your changes

- Keep changes focused. One PR per fix or feature.
- Do not modify the plugin version number — that's done by the maintainer at release time.
- If you're adding a new capability, update `readme.txt` and `README.md` to document it.

### 3. Follow WordPress coding standards

This plugin follows the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Key points:

- Use tabs for indentation (not spaces)
- Prefix all functions, classes, hooks, and options with `xapi_monitor_` or `XAPI_Monitor`
- Use `$wpdb->prepare()` for all database queries with user-supplied values
- Escape all output with appropriate WordPress functions (`esc_html()`, `esc_attr()`, `wp_kses()`, etc.)
- Nonce-protect all AJAX and form actions
- Check capabilities (`current_user_can( 'manage_options' )`) before any privileged action

You can check your code with [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) and the [WordPress Coding Standards sniffs](https://github.com/WordPress/WordPress-Coding-Standards):

```bash
phpcs --standard=WordPress xapi-statement-monitor.php
```

### 4. Test your changes

Before submitting, verify:

- [ ] The plugin activates and deactivates without PHP errors
- [ ] Uninstall removes all tables and options (test on a staging site)
- [ ] The change works with WordPress 6.0+ and PHP 7.4+
- [ ] No existing dashboard functionality is broken
- [ ] No site-specific, personally identifiable, or environment-specific data is hardcoded

If your change touches the Tin Canny hooks or LearnDash integration, test with both plugins active.

### 5. Open the PR

Push your branch and open a pull request against `main`. In the PR description:

- Reference the issue it addresses (e.g., `Closes #42`)
- Describe what changed and why
- Note any testing you did

PRs that include a clear description and evidence of testing are reviewed faster.

---

## Questions?

Open a [GitHub Discussion](https://github.com/barryschoedel/xapi-statement-monitor/discussions) or file an issue with the `question` label.
