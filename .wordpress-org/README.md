# WordPress.org directory images

This folder holds the images of the plugin page in the WordPress.org
directory. At each release they are copied, unchanged, to the `assets/`
folder at the root of the plugin's Subversion repository, next to `trunk/`.
They are never part of the plugin zip.

Only these names are accepted, as described in the
[plugin assets handbook](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/):

| File | Size in pixels | Formats | Limit |
| --- | --- | --- | --- |
| `banner-772x250` | 772 x 250 | PNG, JPG | 4 MB |
| `banner-1544x500` | 1544 x 500, only together with the 772 x 250 banner | PNG, JPG | 4 MB |
| `icon-128x128` | 128 x 128 | PNG, JPG, GIF | 1 MB |
| `icon-256x256` | 256 x 256 | PNG, JPG, GIF | 1 MB |
| `icon.svg` | vector, only together with a PNG icon | SVG | 1 MB |
| `screenshot-N` | any, numbered from 1 | PNG, JPG | 10 MB |

Banners also accept the `-rtl` suffix and a locale suffix (for example
`banner-772x250-es_ES.png`), and screenshots a locale suffix (for example
`screenshot-1-pt_BR.png`). Names are lower case.

Rules for every image:

- No EXIF, XMP, IPTC, text, comment or time blocks.
- Neutral demonstration data only: `example.com` for domains, no real people,
  customers, e-mail addresses, IP addresses or keys, and `api.bladecp.com` as
  the only BladeCP API host.
- Captures come from the released plugin zip installed in a disposable
  WordPress, in the language of the capture. Text inside a capture is never
  edited.
- Each `screenshot-N` has caption number N in the `== Screenshots ==` section
  of `readme.txt`, in the same order. Images and captions are added together.

Screenshots, in order, in English (`screenshot-N.png`), Portuguese
(`screenshot-N-pt_BR.png`) and Spanish (`screenshot-N-es_ES.png`):

1. The check of the site, with what blocks or needs attention first.
2. Connected to a BladeCP account, ready to start the migration.
3. The package being sent, with the stage and the progress.
4. The review: the preview of the migrated copy and the approval.
5. The migration completed, with the optional domain pointing.

They are captures of the real plugin screen, installed from the release zip in
a disposable WordPress that answers as `example.com`, in the language of each
file. The account is shown masked (`j***@example.com`), the preview address is
`preview.example.net` and the A record uses `203.0.113.10`, an address
reserved for documentation. The banners and the icon are rendered from the
approved vector masters of the BladeCP Horizon.
