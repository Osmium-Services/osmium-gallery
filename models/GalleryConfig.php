<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Models;

/**
 * The copy sizes the whole site uses, as the longest side in pixels, read from this service's own settings file
 * (app/config/services/osmium-gallery.json.php, key "gallery.sizes"). The folder names match the shop's (thumb, medium,
 * large, extra). A photo is never scaled up, so a size larger than the photo holds the photo at its own size.
 */
class GalleryConfig
{
    public const DEFAULT_SIZES = ['thumb' => 240, 'medium' => 600, 'large' => 1200, 'extra' => 1920];
    public const MIN_SIDE = 100;
    public const MAX_SIDE = 4000;
    public const PATH = 'app/config/services/osmium-gallery.json.php';

    /**
     * @return array{thumb: int, medium: int, large: int, extra: int} Longest side per size folder, smallest first
     */
    public static function sizes(string $path = self::PATH): array
    {
        $fileExists = \file_exists($path);
        if (!$fileExists) return self::DEFAULT_SIZES;

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) return self::DEFAULT_SIZES;

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart), associative: true);
        $saved = \is_array($decoded) ? ($decoded['gallery']['sizes'] ?? []) : [];

        $sizes = [];
        foreach (self::DEFAULT_SIZES as $folder => $default) {
            $side = (int) ($saved[$folder] ?? 0);
            $sizes[$folder] = self::validSide($side) ? $side : $default;
        }

        return $sizes;
    }

    public static function validSide(int $side): bool
    {
        return $side >= self::MIN_SIDE && $side <= self::MAX_SIDE;
    }
}
