# Marketing illustrations

Seeded raster illustrations for the public landing page, one per scene slug.
The page ships code-drawn scenes regardless; a file listed here replaces the
drawn scene for that slug only. How the files are produced, reviewed and placed
is in [`docs/operations/MARKETING-VISUALS.md`](../../../../../docs/operations/MARKETING-VISUALS.md).

## Files in this folder

| File | What it is |
|---|---|
| `prompts.json` | The prompt pack: shared style block, shared negative cues, one scene per slug with its kind, aspect, target size and alt texts in English and Amharic |
| `manifest.json` | The list of images that exist, written by `scripts/marketing-visuals/generate.mjs` and read by the seeder |
| `<slug>.jpg` or `<slug>.png` | The images themselves, named by scene slug |

## Manifest entry shape

```json
{
  "file": "hero.jpg",
  "kind": "hero",
  "slug": "hero",
  "width": 1440,
  "height": 810,
  "bytes": 412873,
  "alt": "A wide Addis Ababa office with several staff at work and one blank wall screen.",
  "alt_am": "ብዙ ሠራተኞች በሥራ ላይ ያሉበት እና አንድ ባዶ የግድግዳ ስክሪን ያለው ሰፊ የአዲስ አበባ ቢሮ።",
  "provenance": {
    "tool": "replicate",
    "model": "black-forest-labs/flux-1.1-pro",
    "prompt_id": "hero",
    "prompt_version": "2026-10-24.1",
    "generated_at": "2026-10-24T09:12:40.000Z"
  }
}
```

`kind` and `slug` match a scene in `prompts.json` and identify the marketing
item the image belongs to. `file` is relative to this folder. `provenance.tool`
is the provider name, or `import` for a hand-made export placed with
`--import`. The alt texts are copied from the prompt pack at the time of
writing so the manifest stands on its own.

## Rules

- **1 MB per file**, measured as 1,048,576 bytes. The script refuses a larger
  file and does not resize. Export at JPEG quality 85 and 1920 wide, or lower
  the quality, until it fits.
- **JPEG or PNG only**, checked by magic bytes, saved with the matching
  extension. A provider that returns PNG gets a `.png` file and the manifest
  says so.
- **The seeder never overwrites an existing image.** It reads this manifest and
  stores a file only for an item that has none; replacing a live image is a
  console action, not a seed. The script itself skips a slug that already has
  a file unless it is run with `--force`.
- No legible text in any image. Image models garble Ge'ez script, and a wrong
  word in a hero image is worse than none.
