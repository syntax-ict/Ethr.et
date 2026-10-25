# Marketing visuals

How the illustrations on the public landing page are produced, checked and
placed. The page is database-driven: `marketing_sections` and `marketing_items`
hold the copy, and each item that carries a picture gets it from one of the
three routes below. The prompt pack, manifest and images live in
[`api/database/seeders/assets/marketing/`](../../api/database/seeders/assets/marketing/README.md);
the tooling lives in `scripts/marketing-visuals/`.

## The three production routes

| Route | What it is | Who runs it | Status |
|---|---|---|---|
| 1. Code-drawn scenes | SVG scenes drawn in the frontend, one per slug, in the design system's palette | Nobody; they ship with the build | **Ships regardless** |
| 2. The script with a provider key | `scripts/marketing-visuals/generate.mjs` composes the prompt pack and calls an image API | The owner, once, when an API key exists | Ready; needs a key |
| 3. Manual generation in a web UI | The same prompts pasted into Higgsfield, Midjourney or any studio, the export placed with `--import` | The owner or a designer | Ready |

**Decision:** route 1 ships whether or not anything else happens. A seeded
raster from route 2 or 3 replaces the drawn scene **for that slug only**: the
seeder reads `manifest.json`, stores the file for an item that has no image,
and never overwrites one that does. Replacing a live image afterwards is a
console action. The landing page therefore never waits on an API key, and a
half-generated set is a valid state: seven rasters and five drawings read
fine because the prompt pack and the drawn scenes share one palette and one
composition rule.

**The problems rows are animated, not drawn (owner, 2026-10-25).** Each of the
six rows in "The work that fills an HR office" plays a short looping story in
`components/marketing/landing/problem-stories.tsx`: the "Today" pain for six
seconds, then the "With ETHR" answer for six, in SVG moved by CSS, paused off
screen and behind a pause control, and held on its last frame for a reader who
prefers reduced motion. A row added later in the console gets a plain animated
story of its own. An uploaded or seeded picture still replaces the story for
that slug, because an upload is the operator's deliberate choice, and that row
then stops moving. So the six problems prompts below are kept for reference but
**should not be seeded or uploaded**, or the section loses the animation the
owner asked for. **The hero is the same** (owner, later the same day): its
picture is a looping working day in `hero-story.tsx`, sixteen seconds a loop,
and a seeded or uploaded hero image would replace it. The product sequence
below the hero still plays once.

The script is an authoring tool. Nothing in the product calls it, no provider
key is read at runtime, and no dependency was added for it: Node 24, `fetch`
and core modules only.

## Running the script

```bash
node scripts/marketing-visuals/generate.mjs --dry-run --provider replicate
```

prints every composed prompt (style block, scene, negative cues) with no key
and no network. Then, with the provider's key exported in the same shell:

```bash
REPLICATE_API_TOKEN=... node scripts/marketing-visuals/generate.mjs --provider replicate --slug hero
```

```bash
node scripts/marketing-visuals/generate.mjs --provider gemini --slug all
```

Flags: `--provider replicate|gemini|openai|higgsfield` (or
`MARKETING_IMAGE_PROVIDER`), `--slug all|<slug>`, `--force` to regenerate a
slug that already has a file, `--model <id>` to override the adapter's default,
`--input-json '{...}'` to merge extra fields into the provider request. Keys
come from the environment only; any `--key`, `--token` or key-shaped argument
is refused with exit 2, because the command line lands in shell history.

For each scene the script composes the prompt, generates, checks the bytes in
memory (magic bytes, header dimensions, the 1 MB cap), writes
`api/database/seeders/assets/marketing/<slug>.jpg` (or `.png` when the
provider returned PNG) through a temporary file and a rename, upserts the
manifest entry with provenance, and prints the review checklist. A slug whose
manifest entry has an existing file is skipped unless `--force` is passed. Any
failure exits non-zero and leaves no partial file.

The tests run without network:

```bash
node --test scripts/marketing-visuals/generate.test.mjs
```

## Providers

| Provider | Adapter default model | Environment variable | Rough cost per image | Notes |
|---|---|---|---|---|
| Replicate | `black-forest-labs/flux-1.1-pro` | `REPLICATE_API_TOKEN` | about USD 0.04 | Best prompt adherence of the four for flat illustration. Native 16:9; 16:10 scenes are requested as a custom 1440x896. `--model black-forest-labs/flux-1.1-pro-ultra` for larger output at about USD 0.06 |
| Gemini | `gemini-2.5-flash-image` | `GEMINI_API_KEY` | about USD 0.04 | Around 1024 on the short side. 16:10 scenes are requested at 3:2. Google now calls this model legacy and routes newer image models through a different endpoint, so a `--model` override must still answer `generateContent` |
| OpenAI | `gpt-image-1` | `OPENAI_API_KEY` | USD 0.17 to 0.25 at high quality | Only landscape size is 1536x1024 (3:2), used for every scene. Returns JPEG at compression 85 |
| Higgsfield | `higgsfield-ai/soul/v2/standard` | `HIGGSFIELD_API_KEY` as `<key id>:<key secret>`, or `HIGGSFIELD_API_KEY_ID` and `HIGGSFIELD_API_KEY_SECRET` | credits, roughly USD 0.05 | Submission, polling and the result shape are confirmed from the docs; **no size field is documented**, so the adapter sends only the prompt. Prefer the web UI and `--import` for this provider |

Costs are the published list prices on 2026-10-24, rounded; the whole set of
twelve is under USD 3 on any provider, so regenerate freely and keep the best.

**Recommended route, decided 2026-10-24:** Replicate with FLUX 1.1 pro for the
eleven 16:10 scenes, and `--model black-forest-labs/flux-1.1-pro-ultra` for the
hero alone, which is the one image shown full width. It is the only provider of
the four that reaches 1440 wide at the right aspect, it follows a flat
illustration brief most reliably, and the full set costs well under one dollar.
Use Gemini or OpenAI only when no Replicate token exists. Treat Higgsfield as a
web UI, and place its exports with `--import`.

```bash
REPLICATE_API_TOKEN=... node scripts/marketing-visuals/generate.mjs --provider replicate --slug all
```

```bash
REPLICATE_API_TOKEN=... node scripts/marketing-visuals/generate.mjs --provider replicate --slug hero --force --model black-forest-labs/flux-1.1-pro-ultra
```

None of the four APIs produces 1920 wide at these aspect ratios from a single
call, and three of them cannot produce 16:10 at all. The script therefore
accepts an image that is at least 1024 wide, landscape, and within 15% of the
scene's aspect, records the real width and height in the manifest, and prints
a note when the result is below the 1920 target or off the aspect. The page
places each raster with cover cropping that keeps the focal third, which is
why every prompt puts the subject on the left or right third. A 1920 wide
export at the exact aspect comes from route 3, where a web UI can upscale.

## Export settings

For route 3, and for anything regenerated by hand:

- 1920 wide, 16:9 for the hero (1920x1080), 16:10 for every other scene
  (1920x1200). Larger is fine if it fits the cap; smaller than 1024 is refused.
- JPEG, quality 85, progressive, sRGB, metadata stripped. PNG is accepted but
  rarely fits the cap at this size.
- **Under 1 MB (1,048,576 bytes).** The script refuses a larger file and does
  not resize: lower the quality, not the width, until it fits.
- No text layer, no watermark, no border.

## Placing a hand-made export

```bash
node scripts/marketing-visuals/generate.mjs --import ~/Downloads/hero.jpg --slug hero --tool higgsfield-web
```

The file passes exactly the checks a generated one does, is copied to the
assets folder under the slug's name, and gets a manifest entry whose
provenance says `tool: higgsfield-web` (or whatever `--tool` named, `import` by
default), the source file name and the time. `--model` adds the model name to
the provenance when you know it. `--force` replaces an existing image for the
slug; a format change removes the old extension so one slug never has two
files.

## The prompt pack

The pack is `prompts.json` in the assets folder. Every prompt the script
sends is `style` + `Scene: <prompt>` + `Avoid: <negative>`; the dry run prints
them verbatim. It is rendered here so a designer working in a web UI can copy
it without running anything. **The file is the source; if this section and
the file disagree, the file wins and this section needs updating.**

### Style block, prepended to every prompt

> Editorial flat illustration with soft paper grain and simple shapes.
> Palette: deep teal #0F4C75 and deep navy #0A2E4A on warm neutral grounds
> from #F8FAFC to #E2E8F0, with at most one small accent of gold #E8A838. Not
> photorealistic, not 3D, not clip-art, no gradients that look like stock art.
> Contemporary Ethiopian settings and people, as in an Addis Ababa office, a
> factory floor, a hospital ward, a bank branch, a school, a hotel, an NGO
> field office or a shop. Mixed ages and genders, contemporary dress, a
> habesha kemis or a netela where it is natural. One strong focal subject
> placed on the left or right third of the frame, with calm negative space on
> the other side. Every screen, paper, sign and label is blank or abstract: no
> letters, no numbers, no script of any kind. No real brands, logos, device
> models or identifiable people.

### Negative cues, appended to every prompt

> Avoid: legible text, letters, numbers, Ge'ez script, Latin script, signage,
> logos, brand names, watermarks, device model names, identifiable real
> people, flags, coffee ceremony, maps, Lalibela, photorealism, 3D render,
> clip-art, stock photo look, neon colours, extra fingers, distorted hands,
> distorted faces, cluttered composition, centred subject.

### Scenes

| Slug | Kind | Aspect | Scene prompt |
|---|---|---|---|
| `hero` | hero | 16:9 | A wide, bright open-plan office in Addis Ababa seen from slightly above. Six or seven staff of mixed ages and genders work at light wooden desks: one stands talking with a colleague, one wears a netela over office clothes, one carries a folder past a window with morning light and the hazy outline of the city. On the far wall hangs one large blank screen, framed as a quiet focal point on the right third. Potted plants, tidy cables, a water dispenser. The whole scene reads as calm, organised, modern work. |
| `paper_attendance` | problems | 16:10 | A factory floor at shift change. On the left third a supervisor in a work coat holds a thick paper shift register with blank ruled lines and runs a finger down a column. Two workers in overalls stand beside her, one pointing at the page, clearly disputing the hours written down. Behind them, machines, a clock face with no numbers, and a wall rack of blank time cards. The mood is tired and slightly tense, not angry. |
| `manual_payroll` | problems | 16:10 | A small finance office late in the day. On the right third a finance officer in a cardigan sits at a desk recomputing a tax ladder by hand: a desk calculator, a long printed table with blank rows, a pencil, a cup of tea gone cold, several crossed-out sheets. A ring binder of payslips stands open. A desk lamp throws warm light; the window behind is already dark blue. The feeling is of careful, repetitive work that cannot be allowed to be wrong. |
| `leave_spreadsheets` | problems | 16:10 | An HR officer in a hospital administration office, placed on the left third, leaning over a desk covered in printed sheets with blank grids spread edge to edge. She holds one sheet up against another, comparing leave balances, with a highlighter in her other hand. A wall of lever-arch files behind her. Through a doorway, a corridor with a nurse walking past. The composition shows the sheets overlapping and not quite lining up. |
| `branches_offline` | problems | 16:10 | A small regional branch office of a cooperative bank in a highland town, seen through its open front door. On the right third a staff member in a blazer stands by the window holding a phone up toward the sky, its screen blank, the stylised signal arc above it empty. Inside, a colleague waits at a counter with a customer. Outside, eucalyptus trees, a dusty road, hills, a single power line. The mood is patient waiting rather than frustration. |
| `bilingual_staff` | problems | 16:10 | Two colleagues in a hotel back office share one desk and read one blank monitor together, placed on the left third. One, older, wears a netela over her shoulders and points at the screen; the other, younger, in a hotel uniform, leans in with a notebook. Their faces show the small effort of two people reading the same thing in different ways. A reception bell, a key rack and a stack of blank forms sit behind them. |
| `contracts_expiring` | problems | 16:10 | A quiet NGO field office desk, early morning. On the right third a large wall calendar with blank cells and one cell circled in gold hangs above a tall, slightly leaning stack of paper contracts with blank pages and coloured tabs. A pen rests on the top contract. A field jacket on the chair, a window with acacia trees and flat light outside. Nobody is in the frame; the stack and the circled date carry the story. |
| `records` | capabilities | 16:10 | A tidy records room in a school administration block. On the left third an HR officer in a habesha kemis holds one complete employee file, a slim folder with a blank cover, and shows it to a new teacher with visible relief. Behind them, a laptop with a blank screen replaces the usual wall of binders; only a few folders remain on an otherwise clear shelf. Soft daylight from a high window. The feeling is of everything being in one place. |
| `time` | capabilities | 16:10 | A factory gate at seven in the morning, low sun. On the right third a worker in overalls and a high-visibility vest holds up a phone with a blank screen toward the gate, checking in; a small gold tick glows on the screen. Other workers walk in behind him through the open gate, one on a bicycle. A guard hut, a boom barrier, a chain-link fence and the long shadows of early morning. The moment is quick and ordinary. |
| `payroll` | capabilities | 16:10 | Outside a bank branch in Addis Ababa at lunchtime. On the left third a young employee in a shop apron stands on the pavement and opens a payslip on her phone, the screen blank except for an abstract column of bars, smiling slightly. A colleague beside her looks over her shoulder. Behind them the bank's glass front, a minibus taxi passing, pedestrians, a jacaranda tree. The feeling is of knowing, in the moment, what you were paid. |
| `self_service` | capabilities | 16:10 | A hospital corridor with pale walls and a window at the end. On the right third a ward manager in a white coat stands and approves a request on a tablet with one tap, the tablet showing only an abstract card with a gold mark. A nurse who made the request waits a few steps away, relaxed, holding a clipboard with blank paper. A trolley, a wall clock with no numbers, a sign with no letters. The composition is calm and unhurried. |
| `analytics` | capabilities | 16:10 | A small meeting room in an Addis Ababa office. On the left third a large wall screen shows an abstract dashboard: a few teal bars, one navy line, one gold dot, no numbers or labels. Three managers of mixed ages and genders sit and stand around a table looking at it; one gestures at a bar. A glass wall behind them shows the open office. Morning light, a jug of water, notebooks with blank pages. The feeling is of a decision being made from one clear picture. |

The alt texts, English and Amharic, are in the pack beside each scene and are
copied into the manifest when an image is placed.

## Review checklist

The script prints this after every image it writes. Nothing is committed
until a person has looked at the file and ticked every line:

- [ ] No distorted script: every screen, paper and sign is blank or abstract
- [ ] Hands have five fingers and faces are whole
- [ ] No real logos, brand marks or device models
- [ ] Palette holds: teal, navy, warm neutrals, at most one gold accent
- [ ] The focal subject sits on the left or right third, not centred
- [ ] The set reads as one artist when placed beside the other scenes

Two of these fail often with every model: garbled script on a screen that was
asked to be blank, and a sixth finger on a pointing hand. Regenerate rather
than retouch; at these prices a fresh image is cheaper than an edit.

## What the images may not show

The marketing claims rules apply to pictures as much as copy. No device
brands or recognisable terminals, no fingerprint or biometric imagery, no
flags, coffee ceremony, maps or Lalibela, no legible figures that could read
as a claim. A picture of a wall screen with numbers on it is an invented
figure.

## Related

- [`ORGANISATION-NEWS.md`](ORGANISATION-NEWS.md) for the other place public
  images are uploaded, with the same 1 MB discipline
- [`TRUSTED-BY.md`](TRUSTED-BY.md) for what else the landing page shows and
  who decides
