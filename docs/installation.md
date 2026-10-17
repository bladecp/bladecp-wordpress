# Installation

## Before you begin

You need administrator access to the WordPress dashboard of the site you want
to move and a BladeCP account. Check the [compatibility](compatibility.md)
requirements first: the plugin's own check repeats them on your site before
anything is sent.

## Install from the WordPress.org directory

When the plugin is available in the WordPress.org directory:

1. In the WordPress dashboard, open **Plugins > Add New Plugin**.
2. Search for **BladeCP** and install the plugin by BladeCP.
3. Activate it.

## Install from a release zip

Each GitHub release publishes the plugin zip and a `SHA256SUMS` file. Verify
the download before installing it:

```sh
shasum -a 256 -c SHA256SUMS
```

The command must report `OK` for the zip. On Linux, use
`sha256sum -c SHA256SUMS` instead.

1. In the WordPress dashboard, open **Plugins > Add New Plugin > Upload
   Plugin**.
2. Choose `bladecp-migrator-<version>.zip` and click **Install Now**.
3. Activate the plugin.

The zip contains a single `bladecp-migrator/` folder. To install it by hand,
extract it into `wp-content/plugins/` so that
`wp-content/plugins/bladecp-migrator/bladecp-migrator.php` exists, then
activate the plugin in **Plugins**.

## Update

Updates from the WordPress.org directory arrive through the normal WordPress
updates. To update from a release zip, verify the new zip and upload it again:
WordPress offers to replace the installed version. The connection to BladeCP
and a migration in progress are kept.

## Uninstall

**Disconnect** on the plugin screen removes the BladeCP key at any time.
Deleting the plugin in **Plugins** removes its settings, transients,
scheduled task and working folder. Neither action cancels a migration that is
already in BladeCP.

## Next step

Continue with [configuration](configuration.md) to connect the site and start
the migration.
