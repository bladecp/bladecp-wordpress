=== BladeCP Migrator ===
Contributors: bladecp
Tags: migration, migrate, move site, transfer, hosting
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Move this WordPress site to BladeCP Fast Deploy in a few clicks: check, connect, migrate, review and go live.

== Description ==

BladeCP Migrator moves a single WordPress site to BladeCP Fast Deploy with as few clicks as possible. Everything happens on one screen under Tools > BladeCP Migrator.

The plugin is an interface to BladeCP, an external hosting service, and you need a BladeCP account to connect. The section "External services" below explains what is sent to BladeCP and when.

1. **Check your site.** A short check shows the WordPress and PHP versions, the size of your files and database, the free disk space and the PHP limits. Anything that blocks the migration is explained in plain language.
2. **Connect to BladeCP.** You sign in to BladeCP, choose the server that receives the site and come back automatically. No key to copy.
3. **Start migration.** One click builds a package with your files and database, sends it to BladeCP and starts the migration.
4. **Review and go live.** Follow each phase on the same screen, open the preview of the migrated site, confirm your domain when asked and approve the migration. The migration is then complete. Pointing your domain to BladeCP is optional: the screen shows the BladeCP nameservers, or the A record, whenever you want to do it.

Your current site keeps working during the whole process. Nothing is changed on it.

= Built for ordinary hosting =

* Works in short steps that survive low time and memory limits, and continues after a timeout.
* No ZipArchive or shell commands: the package is written as a compressed stream.
* Large files are uploaded in parts, read from disk in small chunks.
* An interrupted upload resumes without sending the parts BladeCP already has.
* Keep the page open for the fastest progress. If you close it, the work continues in the background.

= What is moved =

* All files of the WordPress folder (themes, plugins, uploads and the WordPress core).
* The WordPress database tables (those with your table prefix).

= What is not moved =

* Cache folders and archives of other backup plugins.
* Your wp-config.php file: BladeCP creates a new one with the new database credentials and fresh security keys. Custom settings in wp-config.php must be added again on BladeCP.
* Symbolic links and files PHP cannot read (the check tells you how many).
* Email accounts.

= Requirements and limits =

* A single WordPress site. Multisite networks are not supported by this plugin yet.
* The site must be served from the root of its domain (not from a subfolder such as example.com/blog).
* Your WordPress dashboard must be served over HTTPS to connect to BladeCP.
* Enough free disk space to build the package next to the site (the check estimates it).
* A package of up to 50 GB.
* A 64-bit PHP with OpenSSL (AES-256-GCM) or sodium. The cURL and zlib extensions are recommended.

= Third-party resources =

* The Inter typeface, by The Inter Project Authors, under the SIL Open Font License 1.1 (assets/fonts/OFL.txt). It ships with the plugin and is never loaded from another site.
* Icons from Lucide, under the ISC license, with a few icons derived from Feather under the MIT license (includes/LICENSE-lucide.txt). They are drawn inline as SVG.

= Source code =

The plugin ships its readable source code, with no build step. The public repository is https://github.com/bladecp/bladecp-wordpress.

== External services ==

This plugin is an interface to BladeCP, the hosting platform that receives your site. It contacts BladeCP only after an administrator uses it: nothing is sent when the plugin is activated, and the preflight check runs entirely on your site.

= BladeCP Hub (https://hub.bladecp.com) =

Used to connect this site to your BladeCP account. When you click "Connect to BladeCP", your browser opens the Hub with the address of this site, the address of this admin page, a random state value and a one-time security code challenge (PKCE). You sign in and choose the destination server there. The "Contact support" link also opens a Hub page.

= BladeCP API (https://api.bladecp.com) =

Used for the migration itself. When you come back from the Hub, the plugin sends the one-time authorization code, its verifier and the address of this admin page to receive a connection key. With that key, and only while you follow or act on a migration, it reads the migration status and, when you click the buttons on the screen, requests upload addresses, completes and confirms the upload, confirms the domain, approves, cancels or retries the migration. These requests carry the package size and file name, the upload part checksums returned by the storage, the domain you confirm and the reason for a cancellation, plus the plugin version and the language of the screen. The key only works for the migration you chose and expires after 30 days.

= BladeCP storage =

Receives the migration package after you click "Start migration": a copy of the files of your WordPress folder and of the database tables with your table prefix. Your original wp-config.php, with its database password and security keys, is not included. The package goes in parts, over HTTPS, to the upload addresses returned by the BladeCP API, on storage operated by BladeCP.

BladeCP terms and conditions: https://bladecp.com/terms-and-conditions
BladeCP privacy policy: https://bladecp.com/privacy-policy

== Installation ==

1. In your WordPress dashboard, go to Plugins > Add New Plugin and upload the plugin zip file (or install it from the plugin directory when available).
2. Activate the plugin.
3. Open Tools > BladeCP Migrator and follow the three steps.

== Frequently Asked Questions ==

= Does the plugin change my current site? =

No. It reads your files and database to build the package. Your site keeps working until you point your domain to BladeCP.

= Is my database password sent to BladeCP? =

No. The package does not include your original wp-config.php. BladeCP creates a new one for the new server.

= Where is the package stored before it is sent? =

In a protected folder inside wp-content/uploads, with a random name and rules that block web access. It is deleted after BladeCP confirms the upload, when you stop or cancel, and when you uninstall the plugin.

= My hosting has low PHP limits. Will it work? =

Usually yes. The plugin works in short steps and picks up where it stopped. Low limits only make it slower. The check warns you when something really blocks the migration, such as missing disk space.

= What happens if I close the page? =

The work continues in the background through WP-Cron, more slowly. When WP-Cron is disabled on your site, keep the page open.

= How is the BladeCP key stored? =

Encrypted with your site's security keys (AES-256-GCM through OpenSSL, or sodium when OpenSSL is not available), in a setting that is not loaded on every page. It is never shown. "Disconnect" and uninstalling the plugin remove it.

= Can I migrate a Multisite network? =

Not with this plugin yet.

= How do I remove everything the plugin created? =

Delete the plugin from the Plugins screen. It removes its settings, scheduled tasks, temporary data and its working folder.

== Screenshots ==

1. The check of the site: WordPress and PHP versions, limits, files, database, disk space and HTTPS, with anything that blocks the migration explained first.
2. Connected to a BladeCP account: one click starts the migration.
3. The package is built and sent in short, resumable steps, with the stage and the progress.
4. The migrated copy is ready: open the preview, then approve the migration.
5. The migration is complete. Pointing the domain is optional, with the BladeCP nameservers or the A record ready to copy.

== Changelog ==

= 0.1.0 =
* First release: preflight check, browser connection to BladeCP, resumable package build and upload, migration tracking with preview, domain confirmation, approval, cancel and retry. English, Brazilian Portuguese and Spanish.
* While the preflight check blocks the connection, the "Connect to BladeCP" button explains which checks to fix first.
* The BladeCP key is encrypted with OpenSSL (AES-256-GCM), or with sodium only when OpenSSL is not available. The check only blocks when neither exists.
* The preflight check names the items it cannot copy, and dates follow the language of the screen.
* The review step keeps its buttons on one row, with icons for the preview and for approval.
* A "Contact support" link opens the BladeCP Hub support page.
* A new screen design: a dark header with the three steps, icon cards for the check and a 6-stage trail of the migration. Everything it needs ships with the plugin.
* Approving completes the migration. Pointing the domain is optional, with the BladeCP nameservers (or the A record) ready to copy.
* Released under the MIT License.

== Upgrade Notice ==

= 0.1.0 =
First release.
