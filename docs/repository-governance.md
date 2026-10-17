# Repository governance

This document describes the intended governance for the public
`bladecp/bladecp-wordpress` repository. It is a recommendation for the
repository owners to apply. It does not claim that any GitHub or WordPress.org
setting has already been enabled.

## Public collaboration

The public GitHub repository keeps a focused, reviewable history of the plugin
and its published releases. The WordPress.org Subversion repository is a
release repository: it receives only reviewed releases, never work in
progress.

Public issues and pull requests are welcome. Maintainers review them in GitHub
for transparency. An approved contribution follows the complete validation
and release process before it appears in a published package.

Do not expect a merged public pull request to appear in a release until it
has completed that validation and release cycle.

After the first public release, history remains normal and append-only. Do
not rewrite public commits or force-push public branches.

## Recommended GitHub settings

For the default branch, repository owners should consider enabling:

- Pull requests before merge.
- At least one approving review for changes made by contributors.
- Dismissal of stale approvals when new commits are pushed.
- Required successful checks from the public continuous-integration workflow.
- Conversation resolution before merge.
- Blocked force-pushes and branch deletion.

Restrict release creation and version tags to trusted maintainers. Keep the
repository public only after the first release has passed its content,
security and artifact review.

## Recommended WordPress.org settings

- Keep the committers of the plugin limited to the owner's account and the
  trusted maintainers.
- Enable two-factor authentication on every committer account.
- Use the plugin's release confirmation, so that each new tag must be
  confirmed by e-mail before it reaches users.

## Issues, support and pull requests

Use the issue templates for bugs and feature requests. A good report states
the plugin version, the WordPress and PHP versions, a short description of the
hosting limits, a concise reproduction path and the observed result. Usage
questions belong in the support forum of the plugin in the WordPress.org
directory.

Never include keys, passwords, cookies, site data, database dumps, private
addresses or screenshots containing sensitive information. Use the private
process in [SECURITY.md](../SECURITY.md) for vulnerabilities.

Pull requests should be focused, explain the user-facing outcome and include
appropriate validation notes. Maintainers may request adjustments before a
contribution can be included in a release.

## Releases

Releases are prepared locally from a closed public manifest. They include an
installable zip, a SHA-256 checksum, release notes, a reviewed public source
tree and the WordPress.org Subversion layout. The three-step process is
documented in [the release process](release-process.md).

Preparing a release or creating a local stage never publishes anything. A
separate, explicitly confirmed command is required for an external release.
