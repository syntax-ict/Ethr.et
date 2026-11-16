# ETHR brand assets

Master SVGs for the ETHR mark: a white lowercase e, open at the lower right, with a Sunrise dot at its baseline. These are source files and are not served. The copies the product serves live in `src/public/app-icons/`.

## Colours

| Name | Hex | Use |
|---|---|---|
| ETHR Blue | `#0F4C75` | Tile, address suffix |
| Midnight | `#0A2540` | Wordmark and glyph on light grounds, mono tile |
| Sunrise | `#E8A838` | The dot, always |
| White | `#FFFFFF` | The e on blue or dark grounds |
| Paper | `#F7F5F0` | Warm ground for the marketing site |

### Supporting

| Name | Hex | Use |
|---|---|---|
| Sky | `#4A9FD8` | ETHR Blue in dark mode |
| Mist | `#E8F1F8` | Soft blue panels |
| Cream | `#FDF6E7` | Soft sun panels |
| Honey | `#7C5306` | Sunrise as text |
| Slate | `#475569` | Secondary text |

## Typography

| Face | Use |
|---|---|
| Manrope | Display: headings and dashboard figures |
| Inter | Interface and body text |
| Noto Sans Ethiopic | Ge'ez script, line height 1.7 |

All three are free Google Fonts, self-hosted by `next/font`, so no request reaches Google at runtime.

## In the product

| Brand colour | Tokens in `src/src/styles/globals.css` |
|---|---|
| Midnight | `--text-primary`, `--surface-sidebar`, `--interactive-hover`, `--brand-accent-foreground` |
| ETHR Blue | `--interactive-primary`, `--brand-mark-tile`, `--sidebar-accent` |
| Sunrise | `--brand-accent`, `--brand-mark-dot` |
| Paper | `--surface-paper`, used through the `ground-paper` class on the marketing layout only |
| Sky | `--interactive-primary` in the dark theme |
| Mist | `--primary-soft` |
| Cream | `--brand-soft` |
| Honey | `--brand-on-soft` |
| Slate | `--text-secondary`, `--neutral-on-soft` |

The dark and high-contrast themes keep their own surfaces. Tenant branding, an organisation's own colours and logo, is separate and untouched. Change a brand colour in `globals.css` and nowhere else.

### Contrast

| Pair | Ratio |
|---|---|
| Midnight on white | 15.54:1 |
| Midnight on Paper | 14.26:1 |
| Slate on white | 7.58:1 |
| Slate on Paper | 6.95:1 |
| White on ETHR Blue | 9.09:1 |
| Midnight on Sunrise | 7.46:1 |
| Honey on Cream | 6.30:1 |
| Sunrise on white | 2.08:1, never text |

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
