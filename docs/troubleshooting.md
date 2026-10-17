# Troubleshooting

## "Connect to BladeCP" is disabled

At least one item of the check is marked **Must be fixed**. The sentence
under the button names those items. Fix them and measure the site again.
Common causes are a dashboard served over HTTP, a Multisite network, a site
in a subfolder, missing encryption support in PHP and too little free disk
space.

## The connection was cancelled or expired

If you cancel in the BladeCP Hub, or the connection request expires before
you finish, the plugin screen says so and **Connect to BladeCP** works again.
Start a new connection. A connection request is valid for 15 minutes and can
be used only once.

If BladeCP reports too many unfinished connections for the account, finish or
cancel the pending ones in the BladeCP Hub, then connect again.

## The plugin asks to connect again

The stored key may have expired (30 days), been revoked, or become
unreadable after the security keys of `wp-config.php` changed. Connect again.
The migration in BladeCP is not affected.

## The progress is slow or stops when the page is closed

With the page open, the browser drives the steps. With the page closed,
WP-Cron runs one step per minute. When WP-Cron is disabled, or the site gets
no visits, keep the plugin screen open until the upload finishes.

## The upload was interrupted

Reopen the plugin screen. The upload resumes and does not send again the
parts BladeCP already has. Temporary network or server errors are retried
with a growing pause. After repeated failures the screen shows the error and
the actions you can take.

## Not enough disk space

The package is built next to the site before it is sent. Free some space, or
leave large folders that do not belong to the site out with the
`bladecp_migrator_excluded_paths` filter described in
[configuration](configuration.md), then measure the site again.

## Some files are skipped

Symbolic links and files PHP cannot read are not copied. The check lists up
to five of them. Replace links with the real files, or fix the file
permissions, if those files matter for the site.

## The migration failed or stalled in BladeCP

The screen shows the phase reported by BladeCP and, when BladeCP allows it,
**Try again** or **Cancel migration**. Use **Contact support** on the screen
to reach the right support channel for your account.

## Get help

When you contact support, include the plugin version, the WordPress and PHP
versions and the message shown on the screen. Never send the BladeCP key, your
database password, cookies or a database dump.
