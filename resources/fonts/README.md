# Fonts

Served by the package's asset route next to `admin-v<version>.css`, which refers to them by relative URL.

| File | Font | Coverage | License |
| --- | --- | --- | --- |
| `instrument-sans-latin-v1.woff2` | Instrument Sans (variable, 400–700) | Latin | SIL OFL 1.1, `LICENSE-instrument-sans.txt` |
| `instrument-sans-latin-ext-v1.woff2` | Instrument Sans (variable, 400–700) | Latin Extended | SIL OFL 1.1, `LICENSE-instrument-sans.txt` |
| `noto-sans-arabic-v1.woff2` | Noto Sans Arabic (variable, 100–900) | Arabic script, including Kurdish (Sorani) letters | SIL OFL 1.1, `LICENSE-noto-sans-arabic.txt` |

The files are the unmodified Fontsource 5.3.0 subsets (`@fontsource-variable/instrument-sans`, `@fontsource-variable/noto-sans-arabic`).

Files are cached as immutable. To update a font, add it under a new name (`-v2`), update `AssetController::FONTS` and the `@font-face` rules in `resources/assets/admin.css`, and never replace the bytes of an existing name.
