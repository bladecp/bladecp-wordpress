# Contributing to BladeCP Migrator

Thanks for taking the time to contribute. This repository contains the
official BladeCP Migrator plugin, which moves one WordPress site to
BladeCP Fast Deploy.

## How public contributions are handled

GitHub issues and pull requests are reviewed publicly. An approved change
passes the complete project validation and release review before it appears in
a published package, on GitHub and in the WordPress.org plugin directory.

This means a merged pull request may not appear in a release immediately.
After the first public release, public commits are added normally and are
never rewritten or force-pushed.

Read [repository governance](docs/repository-governance.md) for the complete
policy.

## Scope

Useful contributions include bug fixes, reliability improvements on modest
hosting, accessibility and translation fixes, documentation improvements and
focused enhancements to the migration flow.

Before starting a large feature, open an issue to describe the problem and the
proposed outcome. This helps us confirm scope and avoid duplicate work.

Do not include site data, database dumps, keys, passwords, cookies or other
sensitive information in issues, pull requests, commits, screenshots or test
fixtures.

## Development environment

- PHP 7.4 or later. The plugin must keep running on PHP 7.4.
- WordPress 6.0 or later, in a disposable installation, for any change that
  touches WordPress behavior.
- No Composer installation and no build step: the PHP, CSS and JavaScript
  files of this repository are the files that ship.

Keep changes focused and follow the existing style of the files you modify:
the WordPress coding standards, the `bladecp-migrator` text domain as a
literal string in every translation call, escaping at output, a nonce and the
`manage_options` capability on every action, and the direct-access guard at
the top of each PHP file. The plugin never loads anything from another site
and never writes logs.

## Validation

Before opening a pull request, run:

```sh
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
bash scripts/check-public-tree.sh
```

Run the [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin on
your change in a disposable WordPress and describe the WordPress, PHP and
hosting limits you tested in the pull request. The public continuous
integration runs the same syntax check, the tree check and Plugin Check.
Maintainers also run the complete automated, security and release gates
before a release. A green public check does not replace the final artifact
validation performed before a release.

## Pull requests

1. Start from the current default branch and create a focused branch.
2. Describe the user-facing problem, the chosen solution and the validation
   you performed.
3. Keep unrelated formatting or refactoring out of the pull request.
4. New or changed text on the screen needs the English string; maintainers
   update the bundled translations.
5. Be ready to address review feedback before merge.

## Security reports

Do not use GitHub issues or pull requests to report a vulnerability. Follow
the private reporting process in [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions are licensed under the
project's [MIT License](LICENSE).
