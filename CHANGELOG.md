# Changelog

All notable changes to BladeCP Migrator are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-17

### Added

- Tools > BladeCP Migrator screen with three steps: check, connect, migrate.
- Preflight check: WordPress and PHP versions, single site, site address, HTTPS, PHP build and extensions, PHP limits, files and database size, free disk space and the 50 GB limit.
- "Connect to BladeCP" through the BladeCP Hub with a one-time code, PKCE (S256) and a per-user, single-use state. The key is bound to one migration, encrypted at rest and never shown.
- Encryption at rest with OpenSSL (AES-256-GCM), or with sodium only when OpenSSL is not available. A key stored with either one stays readable while that method is present; an unreadable key asks for a new connection. The preflight check shows encryption in green and only blocks when neither method exists.
- While the preflight check blocks the connection, a short sentence below the disabled "Connect to BladeCP" button names the checks marked in red, and the button points to it for screen readers (`aria-describedby`). It follows the check when the site is measured again.
- When the preflight check finds items it cannot copy (symbolic links or unreadable files), it names up to five of them as path tags, with "N more", so the site owner can tell at once whether they matter.
- Dates on the screen (connection validity) are written the way the screen's language writes them, whatever date format the site uses.
- The review step shows "Open the preview", "Approve migration" and "Cancel migration" on one row with the same height, with icons for the external preview and for approval.
- If the key cannot be encrypted when BladeCP sends the connection back, nothing is stored and the screen explains it, instead of failing.
- A "Contact support" link under the title, and next to the actions when a migration fails or stalls, opens the BladeCP Hub support page, which shows each account the right channel (BladeCP or its hosting partner).
- Screen design: a dark header with the BladeCP horizon and the three steps anchored on its base (the current step lit, an amber counter when a step needs the person), then one card per step. The check shows icon cards with what blocks or needs attention first, and folds into one line once connected.
- A 6-stage trail of the migration (package, upload, analysis, import, preview, complete) drawn from the phase BladeCP reports. It shows a percentage only while the package is built and sent, the only work measured on this site, and 100% once the migration is complete.
- Approving completes the migration: the screen says "Migration completed" with a link to the site or to the migrated copy. Pointing the domain is optional, as in the BladeCP Hub: a card shows the BladeCP nameservers (each one and both at once to copy), the A record as an alternative, a hint for .br domains at Registro.br and, when the automatic check has ended, a note that this does not affect the migration. Nothing on the screen waits for the DNS.
- Admin notices stay above the screen header (`wp-header-end`), and the screen styles stay on this page: scoped CSS, logical properties for right-to-left languages, and no animation with reduced motion.
- The Inter typeface (SIL Open Font License 1.1) and the icons ship with the plugin; the screen loads nothing from other sites.
- One-click migration: resumable file listing, database export in batches and package build as a tar stream in gzip members (plain tar when zlib is missing), in time-boxed steps driven by the page or by WP-Cron.
- Multipart upload streamed from disk with cURL, with resume of the parts BladeCP already has, renewal of expired upload addresses and backoff on temporary errors.
- Migration tracking: phases in plain language, preview link, domain confirmation, approve, cancel and retry, optional domain pointing.
- Clear messages when the connection is cancelled in BladeCP, when the account has too many unfinished connections, or when a connection request expired or was cancelled, always with "Connect to BladeCP" available again. The account is shown by its masked email.
- Disconnect and a complete uninstall (settings, transients, scheduled task, working folder).
- English, Brazilian Portuguese and Spanish translations.
- Released under the MIT License. The plugin zip carries the license and the notices of the bundled Inter typeface and Lucide icons.
