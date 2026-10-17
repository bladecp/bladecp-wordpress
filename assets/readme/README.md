# README artwork

These files illustrate the repository pages. They are not part of the plugin
zip: the plugin screen draws its own marks inline and loads nothing from this
folder.

| File | Use |
| --- | --- |
| `hero.<language>.svg` | Wide hero of the README, with the animated BladeCP Horizon |
| `hero-compact.<language>.svg` | The same hero for screens narrower than 600 px |
| `architecture.<language>.svg` | Logical view of the plugin, as the code works |
| `architecture-compact.<language>.svg` | The same view for narrow screens |
| `logo-light.svg`, `logo-dark.svg` | The BladeCP wordmark for light and dark backgrounds |

`<language>` is `en`, `pt-BR` or `es`, the language of the matching README.
Every text in the artwork is measured, at build time, with the common system
fonts and a wide fallback, so no label overflows its box.

The artwork is static vector content shown through `<img>`: no scripts,
embedded content, event handlers or external references. The only motion is
the light that crosses the Horizon in the hero, a CSS animation that stops when
the reader prefers reduced motion.

`logo-light.svg` is the official BladeCP mark retrieved from
`https://bladecp.com/layout/logo_bladecp.svg` on 2026-07-29, kept as vector
paths only, for light backgrounds. `logo-dark.svg` differs only in the root
`fill` colour (`#ffffff`).

The BladeCP name and marks identify BladeCP. The MIT License of this
repository covers the code; it does not grant rights to use the BladeCP
trademarks beyond referring to this project.
