<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumGallery;

use Osmium\Services\OsmiumGallery\Models\GalleryConfig;
use Osmium\Services\OsmiumGallery\Models\GalleryImage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../core/library/ImageTools.php';
require_once __DIR__ . '/../models/GalleryConfig.php';
require_once __DIR__ . '/../models/GalleryImage.php';

/**
 * The photo writer on real files in a temporary folder.
 */
class GalleryImageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/gallery-' . \uniqid();
        \mkdir($this->root . GalleryImage::DIR, 0755, true);
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->root));
    }

    private function jpeg(int $width, int $height): string
    {
        $path = $this->root . '/upload-' . \uniqid() . '.jpg';
        $image = \imagecreatetruecolor($width, $height);
        \imagejpeg($image, $path);

        return $path;
    }

    private function dir(string $album = 'london'): string
    {
        return $this->root . GalleryImage::DIR . $album . '/';
    }

    public function test_a_wide_photo_is_fitted_on_its_long_edge_and_keeps_its_shape(): void
    {
        $size = GalleryImage::save(sourcePath: $this->jpeg(3000, 2000), album: 'london', name: 'crowd', root: $this->root);

        $this->assertSame(['width' => 3000, 'height' => 2000], $size);
        $this->assertSame([1200, 800], \array_slice(\getimagesize($this->dir() . 'large/crowd.jpg'), 0, 2));
        $this->assertSame([1920, 1280], \array_slice(\getimagesize($this->dir() . 'extra/crowd.jpg'), 0, 2));
        $this->assertSame([240, 160], \array_slice(\getimagesize($this->dir() . 'thumb/crowd.jpg'), 0, 2));
    }

    public function test_a_portrait_photo_is_fitted_on_its_height(): void
    {
        GalleryImage::save(sourcePath: $this->jpeg(1000, 2000), album: 'london', name: 'tall', root: $this->root);

        $this->assertSame([300, 600], \array_slice(\getimagesize($this->dir() . 'medium/tall.jpg'), 0, 2));
    }

    public function test_a_small_photo_is_never_scaled_up(): void
    {
        GalleryImage::save(sourcePath: $this->jpeg(500, 300), album: 'london', name: 'small', root: $this->root);

        $this->assertSame([500, 300], \array_slice(\getimagesize($this->dir() . 'extra/small.jpg'), 0, 2));
    }

    public function test_the_untouched_original_is_kept_apart_and_every_size_has_webp_and_avif_folders(): void
    {
        $upload = $this->jpeg(800, 600);
        GalleryImage::save(sourcePath: $upload, album: 'london', name: 'crowd', root: $this->root);

        $this->assertFileEquals($upload, $this->dir() . 'original/crowd.jpg');
        $this->assertFileExists($this->dir() . 'medium/webp/crowd.webp');
        if (\function_exists('imageavif')) {
            $this->assertFileExists($this->dir() . 'medium/avif/crowd.avif');
        }
        $this->assertSame('', \implode(',', \glob($this->dir() . '{thumb,medium,large,extra}/original*', \GLOB_BRACE)));
    }

    public function test_a_photo_over_the_pixel_limit_is_refused_with_words_and_leaves_nothing_behind(): void
    {
        $path = $this->root . '/huge.png';
        $palette = \imagecreate(7000, 6000);
        \imagecolorallocate($palette, 0, 0, 0);
        \imagepng($palette, $path); // 42,000,000 pixels, a palette image so it stays small in memory

        try {
            GalleryImage::save(sourcePath: $path, album: 'london', name: 'huge', root: $this->root);
            $this->fail('Expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->dir());
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $path = $this->root . '/notes.jpg';
        \file_put_contents($path, 'not an image');

        $this->expectException(\InvalidArgumentException::class);
        GalleryImage::save(sourcePath: $path, album: 'london', name: 'notes', root: $this->root);
    }

    public function test_remove_deletes_the_original_and_every_copy_and_the_empty_folders_but_leaves_other_photos(): void
    {
        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'one', root: $this->root);
        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'two', root: $this->root);

        GalleryImage::remove(album: 'london', name: 'one', root: $this->root);
        $this->assertFileDoesNotExist($this->dir() . 'original/one.jpg');
        $this->assertFileDoesNotExist($this->dir() . 'large/webp/one.webp');
        $this->assertFileExists($this->dir() . 'large/two.jpg');

        GalleryImage::remove(album: 'london', name: 'two', root: $this->root);
        $this->assertDirectoryDoesNotExist($this->dir());
    }

    public function test_remove_ignores_a_name_that_could_leave_the_gallery_folder(): void
    {
        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'one', root: $this->root);

        GalleryImage::remove(album: 'london', name: '../one', root: $this->root);
        GalleryImage::remove(album: '..', name: 'one', root: $this->root);

        $this->assertFileExists($this->dir() . 'original/one.jpg');
    }

    public function test_remove_album_clears_every_file_and_the_folder(): void
    {
        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'one', root: $this->root);
        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'two', root: $this->root);

        GalleryImage::removeAlbum(album: 'london', root: $this->root);

        $this->assertDirectoryDoesNotExist($this->dir());
    }

    public function test_a_name_comes_from_the_file_and_a_clash_gets_a_number(): void
    {
        $this->assertSame('beach-hero', GalleryImage::name(album: 'london', originalName: 'Beach Hero.JPG', root: $this->root));
        $this->assertSame('photo', GalleryImage::name(album: 'london', originalName: '???.png', root: $this->root));

        GalleryImage::save(sourcePath: $this->jpeg(800, 600), album: 'london', name: 'beach-hero', root: $this->root);
        $this->assertSame('beach-hero-2', GalleryImage::name(album: 'london', originalName: 'beach-hero.png', root: $this->root));
    }

    public function test_variants_give_widths_that_match_the_copies_and_skip_repeated_widths(): void
    {
        $wide = GalleryImage::variants(album: 'london', name: 'crowd', width: 3000, height: 2000);
        $this->assertSame([240, 600, 1200, 1920], \array_column($wide, 'width'));
        $this->assertSame('/gallery/london/thumb/crowd', $wide[0]['path']);

        $tall = GalleryImage::variants(album: 'london', name: 'tall', width: 1000, height: 2000);
        $this->assertSame([120, 300, 600, 960], \array_column($tall, 'width'));

        $small = GalleryImage::variants(album: 'london', name: 'small', width: 500, height: 300);
        $this->assertSame(['thumb', 'medium'], \array_column($small, 'size')); // medium, large and extra would all be 500

    }

    public function test_a_stored_name_must_be_lowercase_letters_digits_and_dashes(): void
    {
        $this->assertTrue(GalleryImage::isValidName('london-2026'));
        $this->assertFalse(GalleryImage::isValidName('a/b'));
        $this->assertFalse(GalleryImage::isValidName('..'));
        $this->assertFalse(GalleryImage::isValidName(null));
    }

    public function test_sizes_default_and_follow_a_valid_settings_file(): void
    {
        $this->assertSame(GalleryConfig::DEFAULT_SIZES, GalleryConfig::sizes($this->root . '/missing.json.php'));

        $file = $this->root . '/gallery.json.php';
        \file_put_contents($file, "<?php exit(); ?>\n" . \json_encode(['gallery' => ['sizes' => ['thumb' => 300, 'large' => 5]]]));
        $this->assertSame(['thumb' => 300, 'medium' => 600, 'large' => 1200, 'extra' => 1920], GalleryConfig::sizes($file));
    }
}
