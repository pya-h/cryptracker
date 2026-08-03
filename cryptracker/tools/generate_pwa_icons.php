<?php
/**
 * Dependency-free PWA icon generator for CrypTracker.
 *
 * This project intentionally ships with no image libraries (no GD, no Imagick,
 * no ImageMagick binary). To keep that promise while still producing real PNG
 * launcher icons, this script encodes PNGs by hand using only zlib (gzcompress)
 * and crc32 — both part of core PHP.
 *
 * It draws the app's brand mark (a diamond "◈" on a purple gradient) at the
 * sizes a PWA needs, with 2x supersampling for smooth edges.
 *
 * Run:  php cryptracker/tools/generate_pwa_icons.php
 * Output: public/assets/pwa/{icon-192.png, icon-512.png, icon-maskable-512.png}
 */

$OUT_DIR = dirname(__DIR__, 2) . '/public/assets/pwa';
if (!is_dir($OUT_DIR)) {
    mkdir($OUT_DIR, 0755, true);
}

/* Brand palette (from assets/style.css) */
$TOP    = [0x8b, 0x7b, 0xff]; // lighter purple (top of gradient)
$BOTTOM = [0x5b, 0x48, 0xc9]; // deeper purple (bottom of gradient)
$WHITE  = [0xff, 0xff, 0xff];

/** Linear interpolate two RGB triples. */
function lerp(array $a, array $b, float $t): array
{
    return [
        (int) round($a[0] + ($b[0] - $a[0]) * $t),
        (int) round($a[1] + ($b[1] - $a[1]) * $t),
        (int) round($a[2] + ($b[2] - $a[2]) * $t),
    ];
}

/** Is (x,y) inside a rounded square of side W with corner radius r? */
function insideRoundedRect(float $x, float $y, float $W, float $r): bool
{
    if ($x >= $r && $x <= $W - $r) return true;      // vertical band
    if ($y >= $r && $y <= $W - $r) return true;      // horizontal band
    $cx = ($x < $r) ? $r : $W - $r;
    $cy = ($y < $r) ? $r : $W - $r;
    return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $r * $r;
}

/**
 * Colour of a single (full-resolution) sub-pixel, as [r,g,b,a].
 * $W is the full (supersampled) side length.
 */
function subpixel(float $x, float $y, float $W, bool $maskable, array $TOP, array $BOTTOM, array $WHITE): array
{
    // Background plate: rounded for "any" icons, full-bleed for maskable
    // (the launcher applies its own mask, so we must paint edge-to-edge).
    $radius = $maskable ? 0.0 : 0.20 * $W;
    if (!$maskable && !insideRoundedRect($x, $y, $W, $radius)) {
        return [0, 0, 0, 0]; // transparent corner
    }

    $bg = lerp($TOP, $BOTTOM, $y / ($W - 1));

    // Diamond glyph (kept inside the maskable "safe zone" when maskable).
    $glyphR = ($maskable ? 0.30 : 0.37) * $W;
    $cx = $W / 2.0;
    $cy = $W / 2.0;
    $d  = (abs($x - $cx) + abs($y - $cy)) / $glyphR; // 1.0 at the diamond edge

    if ($d <= 1.0) {
        // Outer ring + centre dot = the "◈" facet look; hollow in between.
        if ($d >= 0.60 || $d <= 0.24) {
            return [$WHITE[0], $WHITE[1], $WHITE[2], 255];
        }
    }
    return [$bg[0], $bg[1], $bg[2], 255];
}

/** Render an S×S RGBA icon (2x supersampled) and return raw pixel bytes. */
function renderPixels(int $S, bool $maskable, array $TOP, array $BOTTOM, array $WHITE): string
{
    $SS = 2;               // supersampling factor
    $W  = $S * $SS;
    $inv = 1.0 / ($SS * $SS);
    $raw = '';

    for ($oy = 0; $oy < $S; $oy++) {
        $raw .= "\x00"; // PNG filter type 0 (None) for this scanline
        for ($ox = 0; $ox < $S; $ox++) {
            $r = $g = $b = $a = 0.0;
            for ($sy = 0; $sy < $SS; $sy++) {
                for ($sx = 0; $sx < $SS; $sx++) {
                    $p = subpixel($ox * $SS + $sx + 0.5, $oy * $SS + $sy + 0.5, $W, $maskable, $TOP, $BOTTOM, $WHITE);
                    // premultiply by alpha so transparent corners blend cleanly
                    $af = $p[3] / 255.0;
                    $r += $p[0] * $af;
                    $g += $p[1] * $af;
                    $b += $p[2] * $af;
                    $a += $p[3];
                }
            }
            $aAvg = $a * $inv;
            if ($aAvg < 0.5) {
                $raw .= "\x00\x00\x00\x00";
                continue;
            }
            // un-premultiply
            $af = ($a * $inv) / 255.0;
            $raw .= chr(min(255, (int) round(($r * $inv) / $af)));
            $raw .= chr(min(255, (int) round(($g * $inv) / $af)));
            $raw .= chr(min(255, (int) round(($b * $inv) / $af)));
            $raw .= chr((int) round($aAvg));
        }
    }
    return $raw;
}

/** Wrap a raw RGBA pixel buffer into a valid PNG (8-bit, colour type 6). */
function encodePng(int $S, string $rawPixels): string
{
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
            . pack('N', crc32($type . $data));
    };

    $ihdr = pack('N', $S) . pack('N', $S) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    $idat = gzcompress($rawPixels, 9);

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', $ihdr)
        . $chunk('IDAT', $idat)
        . $chunk('IEND', '');
}

$targets = [
    ['file' => 'icon-192.png',          'size' => 192, 'maskable' => false],
    ['file' => 'icon-512.png',          'size' => 512, 'maskable' => false],
    ['file' => 'icon-maskable-512.png', 'size' => 512, 'maskable' => true],
];

foreach ($targets as $t) {
    $pixels = renderPixels($t['size'], $t['maskable'], $TOP, $BOTTOM, $WHITE);
    $png = encodePng($t['size'], $pixels);
    $path = $OUT_DIR . '/' . $t['file'];
    file_put_contents($path, $png);
    printf("wrote %-26s %dx%d  %d bytes\n", $t['file'], $t['size'], $t['size'], strlen($png));
}

echo "done.\n";
