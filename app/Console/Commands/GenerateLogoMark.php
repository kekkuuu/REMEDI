<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Regenerate the small logo derivative that the dashboard loader inlines.
 *
 * The loading screen embeds its mark as a base64 data URI rather than linking
 * public/logo.png, because `php artisan serve` is single-threaded: while the
 * dashboard body is being built (5-12s) the dev server cannot serve anything
 * else, so a linked image arrives only as the loader is being destroyed. See
 * resources/views/dashboard/_loading.blade.php for the measurement.
 *
 * That makes logo-mark.webp a DERIVED file, and derived files go stale in
 * silence -- replace logo.png and the loader keeps showing the old artwork
 * with nothing to indicate why. Hence a command rather than a one-off script
 * in someone's shell history.
 */
class GenerateLogoMark extends Command
{
    protected $signature = 'logo:mark
                            {--source=logo.png : Source image in public/}
                            {--out=logo-mark.webp : Destination in public/}
                            {--width=160 : Width in px (the loader draws it at 76, so this is ~2x)}
                            {--quality=82 : WebP quality}';

    protected $description = 'Regenerate the inlined loader mark from the app logo';

    public function handle(): int
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            $this->error('GD with WebP support is required.');

            return self::FAILURE;
        }

        $source = public_path($this->option('source'));

        if (! is_file($source)) {
            $this->error("Source not found: {$source}");

            return self::FAILURE;
        }

        $src = @imagecreatefrompng($source);

        if (! $src) {
            $this->error("Could not read {$source} as a PNG.");

            return self::FAILURE;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $targetW = max(1, (int) $this->option('width'));
        $targetH = (int) round($h * $targetW / $w);

        $dst = imagecreatetruecolor($targetW, $targetH);

        // Preserve the logo's transparency: without these three the resample
        // lands on a black rectangle, which on the loader's pale green card is
        // considerably worse than no logo at all.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

        $out = public_path($this->option('out'));
        imagewebp($dst, $out, (int) $this->option('quality'));

        imagedestroy($src);
        imagedestroy($dst);

        clearstatcache();
        $bytes = filesize($out);

        $this->info(sprintf(
            'Wrote %s — %dx%d, %s (about %s once base64-inlined).',
            $this->option('out'),
            $targetW,
            $targetH,
            $this->bytes($bytes),
            $this->bytes((int) ($bytes * 4 / 3))
        ));

        if ($bytes > 40 * 1024) {
            $this->warn('That is large for something inlined into every dashboard load — consider a smaller --width.');
        }

        return self::SUCCESS;
    }

    private function bytes(int $n): string
    {
        return $n >= 1024 ? round($n / 1024, 1).'KB' : $n.'B';
    }
}
