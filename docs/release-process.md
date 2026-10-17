# Public release process

This page explains how maintainers prepare and publish BladeCP WordPress
Migrator. The process is intentionally conservative: preparing an artifact
does not publish it.

## The three steps

1. **Prepare.** The maintainers run the release gates on the private
   development source and create a reviewed artifact directory: the plugin
   zip, its `SHA256SUMS`, the release notes, the curated public source tree
   and the WordPress.org Subversion layout (`trunk/` and `assets/`). Nothing
   leaves the maintainer's computer.
2. **Stage.** A local Git repository is created from the public source tree,
   with an independent public history and the matching `v<version>` tag. It
   has no remote and nothing is pushed.
3. **Publish.** A separate command, which requires the exact version
   confirmation and an additional safety lock, pushes the staged commit and
   tag to this repository and creates the GitHub release with the zip,
   `SHA256SUMS` and the release notes. It never force-pushes. The same
   reviewed `trunk/` and `assets/` are committed to the WordPress.org
   Subversion repository by the account that owns the plugin there.

## What is released

Every public release includes:

- an installable zip with a single `bladecp-migrator/` folder that contains
  only the plugin files;
- `SHA256SUMS` so users can verify the downloaded zip;
- release notes for that version;
- a curated public source tree;
- the same plugin files in the WordPress.org directory, where `Stable tag`
  points to the `tags/<version>` copy of `trunk/`.

The zip is reproducible: the same plugin files and release date always give
the same bytes and checksum.

## Safety checks

The preparation checks version consistency across the plugin header, the
version constant, `readme.txt` and the changelog, PHP syntax on PHP 7.4 and
8.x, the automated tests, translations, the public tree structure, links,
secret patterns and unintended internal references, in the public tree and
again inside the zip. It also checks the WordPress.org rules for the plugin
header, `readme.txt` and the directory images, and records an audit report.

Automated checks cannot judge every security or editorial concern. The first
public release and material changes receive a human review of code,
documentation, translations, directory images and release assets, and the
zip is validated on its own in a disposable WordPress with the
[Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin.

## History policy

The first public release starts a clean, curated public history. Later
releases add ordinary reviewed commits and version tags to that history. The
private development source remains authoritative: private history is never
mirrored or copied into the public repository, and public history is never
rewritten after the first release.

Public contributions are reviewed in GitHub, then incorporated into the
private development source before returning in a later curated public export.
See [repository governance](repository-governance.md).
