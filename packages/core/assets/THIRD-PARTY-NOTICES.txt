# Third-party notices

FluxFiles itself is MIT-licensed (see `LICENSE`). The files below are vendored
into the shipped frontend/build and keep their own licences. Each is
distributed unmodified, with its licence text next to it.

| Component | Version | Licence | Copyright | Where | Licence text |
|---|---|---|---|---|---|
| [Alpine.js](https://github.com/alpinejs/alpine) | 3.15.12 | MIT | Caleb Porzio and contributors | `assets/vendor/alpine/alpine.min.js` | `assets/vendor/alpine/LICENSE` |
| [CodeMirror 5](https://codemirror.net/5/) (core, `addon/mode/simple`, language `mode/*`, `theme/material-darker`) | 5.65.16 | MIT | 2017 Marijn Haverbeke and others | `assets/vendor/codemirror/` | `assets/vendor/codemirror/LICENSE` |
| [DejaVu Sans](https://dejavu-fonts.github.io/) (text watermarks) | 2.34 | Bitstream Vera Fonts licence + Arev Fonts licence (permissive, free to redistribute; DejaVu's own changes are public domain) | 2003 Bitstream, Inc.; 2006 Tavmjong Bah | `assets/fonts/DejaVuSans.ttf` | `assets/fonts/LICENSE` |

Versions were read from the files themselves (`CodeMirror.version`, the Alpine
build banner recorded in its `LICENSE`, the font's `name` table).

## Composer dependencies

PHP dependencies (Flysystem, AWS SDK, firebase/php-jwt, Intervention Image,
vlucas/phpdotenv, ZipStream, ...) are installed by Composer into `vendor/` and
carry their own licence files there. They are not copied into this repository.
The WordPress plugin zip bundles that `vendor/` tree, with those licence files.

## Not bundled

The optional "bring your own" embeds (terminal, PDF tools, office, e-sign) load
the operator's own instance in an iframe; no third-party code is shipped for them.
