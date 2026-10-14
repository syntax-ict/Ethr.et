<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Support\TenantBrand;
use App\Support\TenantLanding;
use Illuminate\Support\Facades\Storage;

/**
 * The picture a shared link to an organisation's portal shows: 1200 by 630,
 * its brand colour, its mark or logo or initial, its name and headline, and
 * its address on ETHR. Drawn with GD from what the organisation already shows
 * to anyone, written to storage under a version that is a hash of those
 * inputs, so a change makes a new URL and the old one can be cached for a
 * year like every other brand image.
 *
 * Two faces, chosen per word: Noto Sans Ethiopic for Ethiopic script and
 * DejaVu Sans for everything else, because neither carries the other's
 * glyphs and a name is often Latin under an Amharic headline.
 */
final class PortalPreviewImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    private const MARGIN = 80;

    public static function version(Tenant $tenant): string
    {
        $landing = TenantLanding::present($tenant);
        $brand = TenantBrand::present($tenant);

        return substr(hash('sha256', json_encode([
            'v2',
            $tenant->name,
            $landing['headline'],
            $brand['primary_color'],
            $brand['secondary_color'],
            $brand['mark_url'],
            $brand['logo_url'],
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** The PNG, drawn once per version and read from storage after. */
    public function bytes(Tenant $tenant, string $version): string
    {
        $disk = Storage::disk((string) config('filesystems.default'));
        $path = "tenants/{$tenant->public_id}/branding/preview-{$version}.png";
        if ($disk->exists($path)) {
            return $disk->get($path);
        }

        $png = $this->render($tenant);
        $disk->put($path, $png);

        return $png;
    }

    private function render(Tenant $tenant): string
    {
        $landing = TenantLanding::present($tenant);
        $brand = TenantBrand::present($tenant);
        $primary = self::rgb($brand['primary_color'] ?? '#0F4C75');
        $ink = self::luminance($primary) > 0.4 ? [15, 23, 42] : [255, 255, 255];

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $background = (int) imagecolorallocate($image, ...$primary);
        imagefill($image, 0, 0, $background);

        // A quiet band along the bottom, a shade off the brand colour.
        $band = (int) imagecolorallocatealpha($image, $ink[0], $ink[1], $ink[2], 112);
        imagefilledrectangle($image, 0, self::HEIGHT - 72, self::WIDTH, self::HEIGHT, $band);

        $inkColour = (int) imagecolorallocate($image, ...$ink);
        $soft = (int) imagecolorallocatealpha($image, $ink[0], $ink[1], $ink[2], 40);

        $this->drawMark($image, $tenant, $ink, $primary);

        $textTop = 300;
        $name = $this->wrap(true, 54, $tenant->name, self::WIDTH - 2 * self::MARGIN, 2);
        foreach ($name as $i => $line) {
            $this->drawText($image, true, 54, self::MARGIN, $textTop + $i * 72, $inkColour, $line);
        }
        $headline = $landing['headline'];
        if (is_string($headline)) {
            $lines = $this->wrap(false, 30, $headline, self::WIDTH - 2 * self::MARGIN, 2);
            $top = $textTop + count($name) * 72 + 8;
            foreach ($lines as $i => $line) {
                $this->drawText($image, false, 30, self::MARGIN, $top + $i * 44, $soft, $line);
            }
        }

        $address = (string) preg_replace('#^https?://#', '', rtrim((string) config('app.frontend_url'), '/')).'/'.$tenant->subdomain;
        $this->drawText($image, false, 22, self::MARGIN, self::HEIGHT - 28, $inkColour, $address);
        $powered = 'Powered by ETHR';
        $this->drawText($image, false, 22, self::WIDTH - self::MARGIN - $this->width(false, 22, $powered), self::HEIGHT - 28, $soft, $powered);

        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * The mark, else the logo, else the initial on a tile: 160 px tall at
     * the top left, the same order the page itself uses.
     *
     * @param  array{0: int, 1: int, 2: int}  $ink
     * @param  array{0: int, 1: int, 2: int}  $primary
     */
    private function drawMark(\GdImage $image, Tenant $tenant, array $ink, array $primary): void
    {
        $disk = Storage::disk((string) config('filesystems.default'));
        $path = $tenant->brandAsset('mark')['path'] ?? $tenant->logo_storage_path;
        $source = is_string($path) && $disk->exists($path) ? @imagecreatefromstring($disk->get($path)) : false;

        if ($source !== false) {
            $w = imagesx($source);
            $h = imagesy($source);
            $scale = min(160 / $h, 400 / $w);
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            imagealphablending($image, true);
            imagecopyresampled($image, $source, self::MARGIN, self::MARGIN, 0, 0, $tw, $th, $w, $h);
            imagedestroy($source);

            return;
        }

        // The initial on a tile in the ink colour, the letter in the brand colour.
        $tile = (int) imagecolorallocate($image, ...$ink);
        imagefilledrectangle($image, self::MARGIN, self::MARGIN, self::MARGIN + 160, self::MARGIN + 160, $tile);
        $letter = mb_strtoupper(mb_substr(trim($tenant->name), 0, 1)) ?: 'E';
        $font = self::font(true, $letter);
        $box = imagettfbbox(84, 0, $font, $letter);
        $x = self::MARGIN + (int) round((160 - ($box[2] - $box[0])) / 2) - $box[0];
        $y = self::MARGIN + (int) round((160 + ($box[1] - $box[7])) / 2) - $box[1];
        imagettftext($image, 84, 0, $x, $y, (int) imagecolorallocate($image, ...$primary), $font, $letter);
    }

    /**
     * Draw a line as runs of one script each, every run in the face that has
     * its glyphs, advanced by the run's real advance width (a sentinel after
     * the text, so trailing spaces count, which a glyph box would drop).
     */
    private function drawText(\GdImage $image, bool $bold, int $size, int $x, int $y, int $colour, string $text): void
    {
        foreach (self::runs($text) as $run) {
            $font = self::font($bold, $run);
            imagettftext($image, $size, 0, $x, $y, $colour, $font, $run);
            $x += self::advance($font, $size, $run);
        }
    }

    /** The drawn width of a line, measured the way drawText() lays it out. */
    private function width(bool $bold, int $size, string $text): int
    {
        $width = 0;
        foreach (self::runs($text) as $run) {
            $width += self::advance(self::font($bold, $run), $size, $run);
        }

        return $width;
    }

    /**
     * The text split where the script changes; the spaces stay with the run
     * before them.
     *
     * @return list<string>
     */
    private static function runs(string $text): array
    {
        $runs = [];
        $current = '';
        $ethiopic = null;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $isSpace = preg_match('/^\s$/u', $char) === 1;
            $charEthiopic = $isSpace ? $ethiopic : self::isEthiopic($char);
            if ($ethiopic !== null && $charEthiopic !== $ethiopic) {
                $runs[] = $current;
                $current = '';
            }
            $current .= $char;
            $ethiopic = $charEthiopic;
        }
        if ($current !== '') {
            $runs[] = $current;
        }

        return $runs;
    }

    private static function advance(string $font, int $size, string $text): int
    {
        $with = imagettfbbox($size, 0, $font, $text.'|');
        $bar = imagettfbbox($size, 0, $font, '|');

        return ($with[2] - $with[0]) - ($bar[2] - $bar[0]);
    }

    private static function isEthiopic(string $char): bool
    {
        return preg_match('/[\x{1200}-\x{137F}\x{1380}-\x{139F}\x{2D80}-\x{2DDF}]/u', $char) === 1;
    }

    /**
     * Words wrapped to a width, at most `$maxLines` lines, the last ending in
     * an ellipsis when more would not fit.
     *
     * @return list<string>
     */
    private function wrap(bool $bold, int $size, string $text, int $width, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            if ($this->width($bold, $size, $try) > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
                if (count($lines) === $maxLines) {
                    $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1]).'…';

                    return $lines;
                }
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** Noto Sans Ethiopic for Ethiopic script, DejaVu Sans for the rest. */
    private static function font(bool $bold, string $text): string
    {
        $file = self::isEthiopic($text)
            ? ($bold ? 'NotoSansEthiopic-Bold.ttf' : 'NotoSansEthiopic-Regular.ttf')
            : ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');

        return resource_path('fonts/'.$file);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** @param  array{0: int, 1: int, 2: int}  $rgb */
    private static function luminance(array $rgb): float
    {
        $channel = static function (int $v): float {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }
}
