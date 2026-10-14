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
 * Noto Sans Ethiopic carries both scripts, so an Amharic headline and a
 * Latin name draw from the same face.
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
            'v1',
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
        $bold = resource_path('fonts/NotoSansEthiopic-Bold.ttf');
        $regular = resource_path('fonts/NotoSansEthiopic-Regular.ttf');

        $this->drawMark($image, $tenant, $brand, $ink, $primary);

        $textTop = 300;
        $name = $this->wrap($bold, 54, $tenant->name, self::WIDTH - 2 * self::MARGIN, 2);
        foreach ($name as $i => $line) {
            imagettftext($image, 54, 0, self::MARGIN, $textTop + $i * 72, $inkColour, $bold, $line);
        }
        $headline = $landing['headline'];
        if (is_string($headline)) {
            $lines = $this->wrap($regular, 30, $headline, self::WIDTH - 2 * self::MARGIN, 2);
            $top = $textTop + count($name) * 72 + 8;
            foreach ($lines as $i => $line) {
                imagettftext($image, 30, 0, self::MARGIN, $top + $i * 44, $soft, $regular, $line);
            }
        }

        $address = preg_replace('#^https?://#', '', rtrim((string) config('app.frontend_url'), '/')).'/'.$tenant->subdomain;
        imagettftext($image, 22, 0, self::MARGIN, self::HEIGHT - 28, $inkColour, $regular, (string) $address);
        $powered = 'Powered by ETHR';
        $box = imagettfbbox(22, 0, $regular, $powered);
        imagettftext($image, 22, 0, self::WIDTH - self::MARGIN - ($box[2] - $box[0]), self::HEIGHT - 28, $soft, $regular, $powered);

        ob_start();
        imagepng($image, null, 6);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * The mark, else the logo, else the initial on a tile: 160 px tall at
     * the top left, the same order the page itself uses.
     *
     * @param  array<string, mixed>  $brand
     * @param  array{0: int, 1: int, 2: int}  $ink
     * @param  array{0: int, 1: int, 2: int}  $primary
     */
    private function drawMark(\GdImage $image, Tenant $tenant, array $brand, array $ink, array $primary): void
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
        $bold = resource_path('fonts/NotoSansEthiopic-Bold.ttf');
        $box = imagettfbbox(84, 0, $bold, $letter);
        $x = self::MARGIN + (int) round((160 - ($box[2] - $box[0])) / 2) - $box[0];
        $y = self::MARGIN + (int) round((160 + ($box[1] - $box[7])) / 2) - $box[1];
        imagettftext($image, 84, 0, $x, $y, (int) imagecolorallocate($image, ...$primary), $bold, $letter);
    }

    /**
     * Words wrapped to a width, at most `$maxLines` lines, the last ending in
     * an ellipsis when more would not fit.
     *
     * @return list<string>
     */
    private function wrap(string $font, int $size, string $text, int $width, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($box[2] - $box[0] > $width && $line !== '') {
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
