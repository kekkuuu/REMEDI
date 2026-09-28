<?php

namespace App\Support;

/**
 * A URL for a file in public/ carrying a hash of its CONTENTS, so the file can
 * be cached for a year and still change the moment it does.
 *
 * Content, not mtime: a Vercel build does not keep file modification times,
 * so an mtime version could stay the same across two deploys that changed the
 * file and browsers would keep the old one. Hashed once per request per file.
 */
final class StaticAsset
{
    /** @var array<string, string> */
    private static array $versions = [];

    public static function url(string $path): string
    {
        $version = self::$versions[$path] ??= substr((string) @md5_file(public_path($path)), 0, 12);

        return asset($path).($version !== '' ? '?v='.$version : '');
    }
}
