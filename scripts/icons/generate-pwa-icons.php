<?php

declare(strict_types=1);

/**
 * Generate the three PWA icons that `src/public/manifest.json` and `sw.js`
 * reference and that were never created:
 *
 *   badge-72.png     the notification badge          (manifest + sw.js:110)
 *   checkin-96.png   the "Check In" shortcut         (manifest shortcuts[0])
 *   calendar-96.png  the "My Attendance" shortcut    (manifest shortcuts[1])
 *
 * WHY THIS EXISTS. `docs/audit/BASELINE.md` listed the missing badge among three
 * findings left open deliberately — left because it is service-worker behaviour
 * rather than public-site behaviour, not because anyone judged it acceptable. It
 * recorded ONE missing icon. Writing the sweep that guards it
 * (`src/src/test/pwa-assets.test.ts`) immediately found **three**: the two
 * shortcut icons were missing too, and nothing had ever looked.
 *
 * That is the argument for a sweep over a fix. A missing icon is not a type
 * error, not a lint error, not a broken markdown link and not a failing request
 * in any test: the browser asks for it on a device, in production, and renders
 * the shortcut or notification without it — which reads as a design choice
 * rather than a defect.
 *
 * WHY GENERATED RATHER THAN DRAWN. A binary asset with no source is one nobody
 * can correct later; the next person needing another size has to trace it by eye.
 * Same reasoning that made the deployed `.htaccess` generated rather than
 * hand-edited.
 *
 * WHY THE BADGE IS THE "E" AND NOT THE APP ICON. Its manifest entry declares
 * `"purpose": "monochrome"`, and Android renders a notification badge as an ALPHA
 * MASK — colour is discarded and every opaque pixel is filled with a system
 * accent. Reusing `icon-72.png`, whose artwork is a filled rounded square, would
 * render as a solid blob with an E-shaped hole at roughly 24px. So the glyph
 * itself is the shape: opaque where the "E" is, transparent everywhere else. It is
 * extracted from `icon-512.png` ("the pixels that are white and opaque") rather
 * than re-drawn from measurements, so a redrawn brand mark carries through.
 *
 * The two shortcut icons are the opposite case — they are shown in colour in the
 * launcher's shortcut menu, so they are the brand square plus a white glyph, drawn
 * here. Colours come from the design system: `#0F4C75` is Primary and is also the
 * manifest's own `theme_color`.
 *
 * Everything is drawn at 4x and downsampled, because GD does not antialias
 * `imagefilledellipse` or thick lines; supersampling is what keeps the rounded
 * corners and the checkmark's diagonals clean.
 *
 * Usage:  php scripts/icons/generate-pwa-icons.php [--check]
 *
 * --check renders into memory and compares against the committed files without
 * writing, so a gate can assert the assets still match their source.
 * Exit codes: 0 written/matches, 1 --check mismatch, 2 bad input.
 */
const ICON_DIR = __DIR__.'/../../src/public/icons';
const SOURCE = ICON_DIR.'/icon-512.png';

/** ETHR Primary — docs/CLAUDE.md design system, and manifest.theme_color. */
const BRAND = [0x0F, 0x4C, 0x75];
const SUPERSAMPLE = 4;

function fail(string $message): never
{
    fwrite(STDERR, "generate-pwa-icons: {$message}\n");
    exit(2);
}

function newCanvas(int $size): \GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($img, 255, 255, 255, 127));
    imagealphablending($img, true);

    return $img;
}

function downsample(\GdImage $big, int $size): \GdImage
{
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefilledrectangle($out, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($out, 255, 255, 255, 127));
    imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, imagesx($big), imagesx($big));
    imagedestroy($big);

    return $out;
}

function roundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $colour): void
{
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $colour);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $colour);

    $d = $r * 2;
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $d, $d, $colour);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $d, $d, $colour);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $d, $d, $colour);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $d, $d, $colour);
}

function toPng(\GdImage $img): string
{
    ob_start();
    imagepng($img, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($img);

    return $png;
}

/** A pixel belongs to the glyph when it is opaque and near-white. */
function isGlyphPixel(int $rgba): bool
{
    return (($rgba >> 24) & 0x7F) < 64
        && (($rgba >> 16) & 0xFF) > 200
        && (($rgba >> 8) & 0xFF) > 200
        && ($rgba & 0xFF) > 200;
}

function renderBadge(): string
{
    is_file(SOURCE) || fail('missing source '.SOURCE);

    $src = imagecreatefrompng(SOURCE) ?: fail('could not read the source PNG');
    $w = imagesx($src);
    $h = imagesy($src);

    // Extract at full resolution, downsample after: extracting at 72px directly
    // would alias the glyph's edges into the background before resampling could
    // smooth them.
    $mask = newCanvas($w);
    imagealphablending($mask, false);
    $solid = imagecolorallocatealpha($mask, 255, 255, 255, 0);

    $glyphPixels = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if (isGlyphPixel(imagecolorat($src, $x, $y))) {
                imagesetpixel($mask, $x, $y, $solid);
                $glyphPixels++;
            }
        }
    }

    imagedestroy($src);

    // An all-transparent badge is worse than a crash: it looks installed and shows
    // nothing. Guard both ways the extraction can degenerate.
    $ratio = $glyphPixels / ($w * $h);

    $ratio > 0.02 && $ratio < 0.60
        || fail(sprintf(
            'extracted %.1f%% of the source as glyph, which is not a glyph. '.
            'The white-knockout assumption no longer holds for this artwork.',
            $ratio * 100
        ));

    return toPng(downsample($mask, 72));
}

function renderCheckin(): string
{
    $s = 96 * SUPERSAMPLE;
    $img = newCanvas($s);

    $brand = imagecolorallocate($img, ...BRAND);
    $white = imagecolorallocate($img, 255, 255, 255);

    roundedRect($img, 0, 0, $s - 1, $s - 1, (int) ($s * 0.22), $brand);

    // A tick, drawn as two thick strokes with a rounded joint and rounded ends.
    // imagesetthickness() alone leaves square butts that read as chipped corners
    // once downsampled, so each end gets a disc.
    $t = (int) ($s * 0.105);
    imagesetthickness($img, $t);

    $ax = (int) ($s * 0.26);
    $ay = (int) ($s * 0.52);
    $bx = (int) ($s * 0.43);
    $by = (int) ($s * 0.69);
    $cx = (int) ($s * 0.75);
    $cy = (int) ($s * 0.33);

    imageline($img, $ax, $ay, $bx, $by, $white);
    imageline($img, $bx, $by, $cx, $cy, $white);

    foreach ([[$ax, $ay], [$bx, $by], [$cx, $cy]] as [$px, $py]) {
        imagefilledellipse($img, $px, $py, $t, $t, $white);
    }

    imagesetthickness($img, 1);

    return toPng(downsample($img, 96));
}

function renderCalendar(): string
{
    $s = 96 * SUPERSAMPLE;
    $img = newCanvas($s);

    $brand = imagecolorallocate($img, ...BRAND);
    $white = imagecolorallocate($img, 255, 255, 255);

    roundedRect($img, 0, 0, $s - 1, $s - 1, (int) ($s * 0.22), $brand);

    // Body, then the header band punched back in brand so the sheet reads as a
    // calendar rather than a plain card.
    $l = (int) ($s * 0.20);
    $r = (int) ($s * 0.80);
    $top = (int) ($s * 0.28);
    $bot = (int) ($s * 0.80);

    roundedRect($img, $l, $top, $r, $bot, (int) ($s * 0.045), $white);
    imagefilledrectangle($img, $l, $top, $r, (int) ($s * 0.40), $brand);

    // The two binder rings straddle the header's top edge.
    $ringW = (int) ($s * 0.055);
    foreach ([0.34, 0.60] as $fx) {
        $x = (int) ($s * $fx);
        imagefilledrectangle($img, $x, (int) ($s * 0.20), $x + $ringW, (int) ($s * 0.33), $white);
    }

    // Day marks: three columns, two rows, in brand on the white sheet.
    $cell = (int) ($s * 0.075);
    $gap = (int) ($s * 0.035);
    $startX = (int) ($s * 0.28);
    $startY = (int) ($s * 0.47);

    for ($row = 0; $row < 2; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $x = $startX + $col * ($cell + $gap);
            $y = $startY + $row * ($cell + $gap);
            imagefilledrectangle($img, $x, $y, $x + $cell, $y + $cell, $brand);
        }
    }

    return toPng(downsample($img, 96));
}

extension_loaded('gd') || fail('ext-gd is not loaded');

$targets = [
    'badge-72.png' => renderBadge(),
    'checkin-96.png' => renderCheckin(),
    'calendar-96.png' => renderCalendar(),
];

$check = in_array('--check', array_slice($argv, 1), true);
$bad = 0;

foreach ($targets as $name => $png) {
    $path = ICON_DIR.'/'.$name;

    if ($check) {
        if (! is_file($path) || file_get_contents($path) !== $png) {
            fwrite(STDERR, "generate-pwa-icons --check: {$name} is missing or does not match its source.\n");
            $bad++;

            continue;
        }

        printf("generate-pwa-icons: %s matches\n", $name);

        continue;
    }

    file_put_contents($path, $png);
    printf("generate-pwa-icons: wrote %s (%d bytes)\n", $name, strlen($png));
}

exit($bad > 0 ? 1 : 0);
