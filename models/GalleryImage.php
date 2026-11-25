<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Models;

use Osmium\Core\Library\ImageTools;

/**
 * A photo on disk, in the shop's layout: assets/images/gallery/<album>/<size>/<name>.jpg with webp/ and avif/ folders
 * inside each size folder, so $osmium->imagePath('/gallery/<album>/<size>/<name>') picks the best copy. Every copy is
 * scaled to fit the size on its longest side (the photo keeps its shape, never scaled up). There is no @2x copy: a
 * srcset with width descriptors already gives retina screens the larger file. The untouched upload is kept in
 * <album>/original/<name>.<ext>; no size lookup ever reaches that folder.
 */
class GalleryImage
{
    public const DIR = '/assets/images/gallery/';
    public const ORIGINAL = 'original';
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const QUALITY = ['jpg' => 85, 'webp' => 80, 'avif' => 60];
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** The base name for a new photo from the uploaded file's own name, with -2, -3 added only when the album has one already */
    public static function name(string $album, string $originalName, string $root): string
    {
        $base = self::slug(\pathinfo($originalName, \PATHINFO_FILENAME)) ?: 'photo';
        $name = $base;
        for ($number = 2; self::taken(album: $album, name: $name, root: $root); $number++) {
            $name = $base . '-' . $number;
        }

        return $name;
    }

    /** Whether a name or album slug has the shape this class writes, so a stored value can never point outside the gallery folder */
    public static function isValidName(?string $name): bool
    {
        return $name !== null && \preg_match('#^[a-z0-9-]+$#', $name) === 1;
    }

    /**
     * Checks the file, keeps it untouched in original/, and writes every size as JPG plus WebP and AVIF where this
     * server's GD can write them. Nothing is kept when the file is refused.
     *
     * @return array{width: int, height: int} The photo's own size, upright
     * @throws \InvalidArgumentException when the file is not a usable image or is over the pixel limit (words for the person uploading)
     */
    public static function save(string $sourcePath, string $album, string $name, string $root): array
    {
        $tools = new ImageTools();
        $source = $tools->load(path: $sourcePath, allowedTypes: self::TYPES);

        $dir = $root . self::DIR . $album . '/';
        self::keepOriginal(sourcePath: $sourcePath, dir: $dir, name: $name);
        foreach (GalleryConfig::sizes() as $size => $side) {
            $tools->storeVariants($tools->fit($source, $side), $dir . $size, $name, self::QUALITY);
        }

        return ['width' => \imagesx($source), 'height' => \imagesy($source)];
    }

    /** Deletes the original and every copy of a photo, then any folder that leaves empty. Admin-only paths call this. */
    public static function remove(string $album, ?string $name, string $root): void
    {
        $unusable = !self::isValidName($album) || !self::isValidName($name);
        if ($unusable) return;

        $dir = $root . self::DIR . $album . '/';
        foreach (self::copies(dir: $dir, name: $name) as $path) {
            @\unlink($path);
        }

        self::removeEmptyFolders($dir);
    }

    /** Deletes every file of an album and its folder, for removing an album for good */
    public static function removeAlbum(string $album, string $root): void
    {
        $unusable = !self::isValidName($album);
        if ($unusable) return;

        $dir = $root . self::DIR . $album . '/';
        foreach (\glob($dir . '*/{,webp/,avif/}*', \GLOB_BRACE) ?: [] as $path) {
            @\unlink($path); // A folder fails quietly here and goes below once empty
        }

        self::removeEmptyFolders($dir);
    }

    /**
     * A photo's copies as a theme's img needs them: the extensionless path imagePath() resolves, the width a copy has
     * (scaled the same way as the copy), smallest first. Sizes that would repeat a width (a small photo) are left out.
     *
     * @return list<array{size: string, path: string, width: int}>
     */
    public static function variants(string $album, string $name, int $width, int $height): array
    {
        $variants = [];
        foreach (GalleryConfig::sizes() as $size => $side) {
            $scaled = (int) \max(1, \round($width * \min(1, $side / \max($width, $height))));
            $repeats = \in_array($scaled, \array_column($variants, 'width'), true);
            if ($repeats) continue;

            $variants[] = ['size' => $size, 'path' => '/gallery/' . $album . '/' . $size . '/' . $name, 'width' => $scaled];
        }

        return $variants;
    }

    private static function keepOriginal(string $sourcePath, string $dir, string $name): void
    {
        $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($sourcePath);
        $extension = self::EXTENSIONS[$mime];
        $folder = $dir . self::ORIGINAL;
        $ready = \is_dir($folder) || @\mkdir(directory: $folder, permissions: 0755, recursive: true) || \is_dir($folder);
        if (!$ready) throw new \RuntimeException('Could not create the image folder');

        $copied = \copy($sourcePath, $folder . '/' . $name . '.' . $extension);
        if (!$copied) throw new \RuntimeException('Could not save the image');
    }

    /** @return list<string> */
    private static function copies(string $dir, string $name): array
    {
        $paths = \glob($dir . self::ORIGINAL . '/' . $name . '.*') ?: [];
        foreach (\array_keys(GalleryConfig::DEFAULT_SIZES) as $size) {
            $found = \glob($dir . $size . '/{,webp/,avif/}' . $name . '.*', \GLOB_BRACE) ?: [];
            $paths = \array_merge($paths, $found);
        }

        return $paths;
    }

    private static function taken(string $album, string $name, string $root): bool
    {
        $dir = $root . self::DIR . $album . '/';

        return self::copies(dir: $dir, name: $name) !== [];
    }

    private static function removeEmptyFolders(string $dir): void
    {
        foreach (\array_keys(GalleryConfig::DEFAULT_SIZES) as $size) {
            foreach (['webp/', 'avif/', ''] as $sub) {
                @\unlink($dir . $size . '/' . $sub . '.DS_Store'); // Finder leaves this behind, and it would stop the folder counting as empty
                @\rmdir($dir . $size . '/' . $sub); // Only succeeds on an empty folder
            }
        }

        @\rmdir($dir . self::ORIGINAL);
        @\rmdir($dir);
    }

    public static function slug(string $text): string
    {
        return \substr(\trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($text)), '-'), 0, 60);
    }
}
