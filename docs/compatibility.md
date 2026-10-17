# Compatibility

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.0 or later, tested up to the version in the `Tested up to` field of `readme.txt` |
| PHP | 7.4 or later, 64-bit |
| Encryption | OpenSSL with AES-256-GCM, or sodium |
| Recommended PHP extensions | cURL (uploads stream from disk) and zlib (compressed package) |
| Site type | A single site served from the root of its domain. Multisite networks, sites in a subfolder such as `example.com/blog` and WordPress installed in a folder apart from the site address are not supported yet. |
| Dashboard | Served over HTTPS to connect to BladeCP |
| Database | MySQL or MariaDB, as supported by the installed WordPress |
| Disk space | Enough free space to build the package next to the site. The check estimates it. |
| Package size | Up to 50 GB |
| Network | Outbound HTTPS to `api.bladecp.com` and to the upload addresses that BladeCP returns |
| Browser | A current browser that runs the WordPress dashboard |
| Languages | English, Portuguese (Brazil) and Spanish |

The plugin does not need Composer, ZipArchive, shell commands or a build
step.

## Modest hosting

Every step works for half of `max_execution_time`, between 1 and 20
seconds, and stops before 80% of `memory_limit`, then saves its progress. Low
limits make the migration slower, not impossible. A step interrupted by a
timeout is cut back and done again.

Without cURL, an upload part is sent from memory only when it fits within the
memory limit with a safe margin; otherwise the check blocks the migration and
explains why.

## Validation snapshot

The validation of `0.1.0` included WordPress 7.1.3 on PHP 8.2 with low
limits (64 MB of memory and a 4 second time limit), a database export from
MariaDB 10.11 imported into another database with identical table checksums,
and PHP syntax checks on PHP 7.4 and 8.x. These are tested environments, not
additional requirements. Run the check of the plugin on your own site before
you start.
