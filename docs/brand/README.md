# ETHR brand assets

Master SVGs for the ETHR mark: a white lowercase e, open at the lower right, with a Sunrise dot at its baseline. These are source files and are not served. The copies the product serves live in `src/public/app-icons/`.

## Colours

| Name | Hex | Use |
|---|---|---|
| ETHR Blue | `#0F4C75` | Tile, address suffix |
| Midnight | `#0A2540` | Wordmark and glyph on light grounds, mono tile |
| Sunrise | `#E8A838` | The dot, always |
| White | `#FFFFFF` | The e on blue or dark grounds |

## Files

| File | Use |
|---|---|
| [ethr-symbol.svg](ethr-symbol.svg) | The app tile, 48 px and up |
| [ethr-symbol-small.svg](ethr-symbol-small.svg) | The tile at 32 px and below, with heavier strokes and a larger dot |
| [ethr-symbol-mono.svg](ethr-symbol-mono.svg) | One-colour print and stamps: Midnight tile, white e and dot |
| [ethr-glyph.svg](ethr-glyph.svg) | The e and dot with no tile, on light grounds |
| [ethr-glyph-reversed.svg](ethr-glyph-reversed.svg) | The e and dot with no tile, on dark grounds |
| [ethr-wordmark.svg](ethr-wordmark.svg) | "ethr" on light grounds |
| [ethr-wordmark-reversed.svg](ethr-wordmark-reversed.svg) | "ethr" on dark grounds |
| [ethr-lockup.svg](ethr-lockup.svg) | Tile plus wordmark, light grounds |
| [ethr-lockup-reversed.svg](ethr-lockup-reversed.svg) | Glyph plus wordmark, dark grounds |
| [ethr-address.svg](ethr-address.svg) | "ethr.et" for tenant addresses and the domain |

## Rules

- Clear space: one quarter of the tile's width on every side.
- Minimum size: symbol 16 px on screen, 6 mm in print. Wordmark 64 px wide on screen, 18 mm in print.
- Never retype the wordmark in a font. It is drawn; use the SVG.
- Never recolour the dot or stretch the art.

## Generated files

The PNG icons and `favicon.ico` are built from these masters, never edited by hand. From `src/`:

```bash
node scripts/brand-icons.mjs
```

That rewrites `icon-*`, `maskable-*`, `apple-touch-icon`, `badge-72` in `src/public/app-icons/` and `src/public/favicon.ico`. `src/public/app-icons/favicon.svg` is a copy of `ethr-symbol-small.svg`; update it by hand when that master changes.
