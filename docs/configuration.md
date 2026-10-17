# Configuration

The plugin has no settings page. Everything happens on one screen under
**Tools > BladeCP Migrator**, in three steps.

## 1. Check the site

The check runs on your site only. It shows the WordPress and PHP versions,
whether the site is a single site served from the root of its domain, the
HTTPS address of the dashboard, the PHP build and extensions, the PHP limits,
the size of the files and of the database, and the free disk space.

The size of the files is measured in short steps while the page is open and
stays valid for one hour. An item marked **Must be fixed** keeps
**Connect to BladeCP** and **Start migration** disabled, and the screen names
the items to fix first. Items that only need attention, such as low PHP
limits or files that cannot be copied, do not block the migration.

## 2. Connect to BladeCP

**Connect to BladeCP** opens the BladeCP Hub in the same browser tab. Sign in,
choose the server that receives the site and confirm. The Hub sends you back
to the plugin screen, which shows the masked e-mail address of the connected
account.

- The connection uses a one-time code with PKCE (S256) and a single-use state
  that belongs to your WordPress user and expires after 15 minutes.
- The key the plugin receives only works for the migration you chose and
  expires after 30 days. It is encrypted with the security keys of the site
  and never displayed.
- Changing the security keys (salts) of `wp-config.php` makes the stored key
  unreadable. Connect again in that case.
- **Disconnect** removes the key, the local job and the local package files.
  It does not cancel the migration in BladeCP.

## 3. Start the migration

**Start migration** lists the files, exports the database tables with the
site's table prefix, builds the package and sends it to BladeCP in parts. The
screen shows the progress and a trail of six stages: package, upload,
analysis, import, preview and complete.

- Keep the page open for the fastest progress. If you close it, WP-Cron runs
  one step per minute in the background. When WP-Cron is disabled on the
  site, keep the page open.
- After the upload, follow each phase on the same screen. Open the preview of
  the migrated site, confirm the domain when BladeCP asks for it, and approve.
  Approval completes the migration.
- Pointing the domain to BladeCP is optional and never blocks the migration.
  The screen shows the BladeCP nameservers and, as an alternative, the A
  record.
- **Cancel migration** and **Try again** are available while BladeCP allows
  them.

## Leaving paths out of the package

Cache folders, the working folder of the plugin and the archives of common
backup plugins are left out automatically. The original `wp-config.php` is
never sent: BladeCP writes a new one for the new server. A site that needs to
leave other folders out can add paths relative to the WordPress folder with
the `bladecp_migrator_excluded_paths` filter:

```php
add_filter(
	'bladecp_migrator_excluded_paths',
	function ( array $paths ) {
		$paths[] = 'wp-content/large-archive';
		return $paths;
	}
);
```
