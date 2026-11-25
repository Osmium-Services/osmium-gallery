<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumGallery;

use Osmium\Services\OsmiumGallery\Models\Album;
use Osmium\Services\OsmiumGallery\Models\Gallery;
use Osmium\Services\OsmiumGallery\Models\GalleryConfig;
use Osmium\Services\OsmiumGallery\Models\GalleryImage;
use Osmium\Services\OsmiumGallery\Models\Media;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/Album.php';
require_once __DIR__ . '/../models/Media.php';
require_once __DIR__ . '/../models/GalleryConfig.php';
require_once __DIR__ . '/../models/GalleryImage.php';
require_once __DIR__ . '/../models/Gallery.php';

/**
 * What a theme gets from Gallery against the installed tables (rolled back with each test), with a stand-in for
 * imagePath() that finds every copy except the ones a test says are missing.
 */
class GalleryApiTest extends DatabaseTestCase
{
    private function gallery(array $missing = []): Gallery
    {
        $imagePath = static fn(string $path): string => \in_array($path, $missing, true) ? '' : '/assets/images' . $path . '.avif';

        return new Gallery($this->db, $imagePath);
    }

    private function album(string $slug, ?string $date, bool $published = true): int
    {
        $details = ['name' => 'Album ' . $slug, 'slug' => $slug, 'blurb' => null, 'eventDate' => $date, 'showId' => null, 'sortOrder' => 1, 'published' => $published];

        return (new Album($this->db))->create($details);
    }

    private function photo(int $albumId, string $name, int $width = 3000, int $height = 2000, ?string $alt = null, ?string $caption = null): int
    {
        $details = ['albumId' => $albumId, 'fileName' => $name, 'originalName' => $name . '.jpg', 'width' => $width, 'height' => $height, 'caption' => $caption, 'alt' => $alt, 'sortOrder' => (new Media($this->db))->nextSort($albumId)];

        return (new Media($this->db))->create($details);
    }

    public function test_an_item_has_a_src_a_multi_entry_srcset_its_shape_and_alt(): void
    {
        $id = $this->album('london', '2026-05-01');
        $this->photo(albumId: $id, name: 'crowd', alt: 'A crowd at the stand', caption: 'Day one');

        $item = $this->gallery()->album('london')['media'][0];

        $this->assertSame('image', $item['type']);
        $this->assertSame('/assets/images/gallery/london/large/crowd.avif', $item['src']);
        $this->assertSame(
            '/assets/images/gallery/london/thumb/crowd.avif 240w, /assets/images/gallery/london/medium/crowd.avif 600w, /assets/images/gallery/london/large/crowd.avif 1200w, /assets/images/gallery/london/extra/crowd.avif 1920w',
            $item['srcset'],
        );
        $this->assertSame([1200, 800], [$item['width'], $item['height']]);
        $this->assertSame('A crowd at the stand', $item['alt']);
        $this->assertSame('Day one', $item['caption']);
    }

    public function test_alt_falls_back_to_the_caption_then_the_album_name(): void
    {
        $id = $this->album('london', '2026-05-01');
        $this->photo(albumId: $id, name: 'a', caption: 'Day one');
        $this->photo(albumId: $id, name: 'b');

        $items = $this->gallery()->album('london')['media'];

        $this->assertSame('Day one', $items[0]['alt']);
        $this->assertSame('Album london', $items[1]['alt']);
    }

    public function test_a_size_missing_on_disk_is_left_out_of_the_srcset(): void
    {
        $id = $this->album('london', '2026-05-01');
        $this->photo(albumId: $id, name: 'crowd');

        $item = $this->gallery(missing: ['/gallery/london/extra/crowd'])->album('london')['media'][0];

        $this->assertStringNotContainsString('1920w', $item['srcset']);
        $this->assertSame('/assets/images/gallery/london/large/crowd.avif', $item['src']);
    }

    public function test_a_small_photo_gets_a_srcset_without_repeated_widths(): void
    {
        $id = $this->album('london', '2026-05-01');
        $this->photo(albumId: $id, name: 'small', width: 500, height: 300);

        $item = $this->gallery()->album('london')['media'][0];

        $this->assertSame(2, \substr_count($item['srcset'], 'w'));
        $this->assertSame([500, 300], [$item['width'], $item['height']]);
    }

    public function test_only_published_albums_are_shown_and_a_draft_has_no_media(): void
    {
        $draft = $this->album('draft', '2026-01-01', published: false);
        $this->photo(albumId: $draft, name: 'a');
        $live = $this->album('live', '2026-02-01');
        $this->photo(albumId: $live, name: 'b');
        $gallery = $this->gallery();

        $slugs = \array_column($gallery->albums(), 'slug');
        $this->assertContains('live', $slugs);
        $this->assertNotContains('draft', $slugs);
        $this->assertNull($gallery->album('draft'));
        $this->assertSame([], $gallery->media($draft));
        $this->assertCount(1, $gallery->media($live));
    }

    public function test_binned_photos_are_not_returned_and_the_cover_is_the_chosen_or_first_photo(): void
    {
        $id = $this->album('london', '2026-05-01');
        $first = $this->photo(albumId: $id, name: 'first');
        $second = $this->photo(albumId: $id, name: 'second');
        $gone = $this->photo(albumId: $id, name: 'gone');
        (new Media($this->db))->softDelete($gone);

        $this->assertCount(2, $this->gallery()->media($id));
        $this->assertStringContainsString('first', $this->gallery()->album('london')['cover']['src']);

        (new Album($this->db))->setCover(id: $id, mediaId: $second);
        $this->assertStringContainsString('second', $this->gallery()->album('london')['cover']['src']);
    }

    public function test_an_empty_album_has_no_cover(): void
    {
        $this->album('empty', '2026-05-01');

        $this->assertNull($this->gallery()->album('empty')['cover']);
    }

    public function test_albums_group_by_year_newest_first_with_undated_last(): void
    {
        $this->album('a-2025', '2025-03-01');
        $this->album('b-2026', '2026-05-01');
        $this->album('c-2026', '2026-09-01');
        $this->album('d-none', null);
        $gallery = $this->gallery();

        $years = $gallery->byYear($gallery->albums());
        $mine = \array_filter($years, static fn(array $albums): bool => \array_intersect(\array_column($albums, 'slug'), ['a-2025', 'b-2026', 'c-2026', 'd-none']) !== []);

        $keys = \array_keys($mine);
        $this->assertSame(2026, $keys[0]);
        $this->assertContains(2025, $keys);
        $this->assertSame(0, \end($keys));
    }

    public function test_a_video_item_uses_its_poster_and_keeps_its_source(): void
    {
        $id = $this->album('clips', '2026-05-01');
        (new Media($this->db))->create(['albumId' => $id, 'fileName' => 'clip-1', 'originalName' => null, 'type' => 'video', 'source' => 'https://example.com/v/1', 'meta' => ['poster' => 'clip-1-poster'], 'width' => 1600, 'height' => 900, 'sortOrder' => 1]);

        $item = $this->gallery()->album('clips')['media'][0];

        $this->assertSame('video', $item['type']);
        $this->assertSame('https://example.com/v/1', $item['source']);
        $this->assertStringContainsString('/clips/large/clip-1-poster', $item['src']);
    }
}
