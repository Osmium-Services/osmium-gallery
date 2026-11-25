<?php

declare(strict_types=1);

namespace Tests\Services\OsmiumGallery;

use Osmium\Services\OsmiumGallery\Models\Album;
use Osmium\Services\OsmiumGallery\Models\Media;
use Tests\Integration\DatabaseTestCase;

require_once __DIR__ . '/../models/Album.php';
require_once __DIR__ . '/../models/Media.php';

/**
 * Albums and media against the installed tables (rolled back with each test; the service must be installed, because
 * creating the tables here would commit the test's transaction).
 */
class GalleryModelsTest extends DatabaseTestCase
{
    private function album(string $slug = 'london-2026', bool $published = true): int
    {
        $details = ['name' => 'London 2026', 'slug' => $slug, 'blurb' => null, 'eventDate' => '2026-05-01', 'showId' => null, 'sortOrder' => 1, 'published' => $published];

        return (new Album($this->db))->create($details);
    }

    private function photo(int $albumId, string $fileName, int $sort): int
    {
        $details = ['albumId' => $albumId, 'fileName' => $fileName, 'originalName' => $fileName . '.jpg', 'width' => 1200, 'height' => 800, 'sortOrder' => $sort];

        return (new Media($this->db))->create($details);
    }

    public function test_an_album_is_created_listed_published_and_found_by_slug(): void
    {
        $albums = new Album($this->db);
        $id = $this->album();
        $draft = $this->album(slug: 'draft', published: false);

        $this->assertSame('London 2026', $albums->getById($id)['name']);
        $this->assertSame('london-2026', $albums->getPublishedBySlug('london-2026')['slug']);
        $this->assertNull($albums->getPublishedBySlug('draft'));
        $this->assertContains($id, \array_map('intval', \array_column($albums->listPublished(), 'id')));
        $this->assertNotContains($draft, \array_map('intval', \array_column($albums->listPublished(), 'id')));

        $albums->setPublished(id: $draft, published: true);
        $this->assertNotNull($albums->getPublishedBySlug('draft'));
    }

    public function test_a_slug_in_use_is_reported_even_by_a_binned_album_but_not_against_itself(): void
    {
        $albums = new Album($this->db);
        $id = $this->album();

        $this->assertTrue($albums->slugTaken('london-2026'));
        $this->assertFalse($albums->slugTaken(slug: 'london-2026', exceptId: $id));

        $albums->softDelete($id);
        $this->assertTrue($albums->slugTaken('london-2026'));
        $this->assertNull($albums->getById($id));
        $this->assertNotNull($albums->getAnyById($id));
    }

    public function test_an_album_can_be_binned_restored_and_removed_for_good(): void
    {
        $albums = new Album($this->db);
        $id = $this->album();

        $albums->softDelete($id);
        $this->assertContains($id, \array_map('intval', \array_column($albums->listDeleted(), 'id')));
        $albums->restore($id);
        $this->assertNotNull($albums->getById($id));
        $albums->hardDelete($id);
        $this->assertNull($albums->getAnyById($id));
    }

    public function test_media_is_added_last_reordered_and_the_cover_is_set(): void
    {
        $albums = new Album($this->db);
        $media = new Media($this->db);
        $albumId = $this->album();

        $this->assertSame(1, $media->nextSort($albumId));
        $first = $this->photo($albumId, 'a', 1);
        $second = $this->photo($albumId, 'b', $media->nextSort($albumId));
        $third = $this->photo($albumId, 'c', $media->nextSort($albumId));
        $this->assertSame([$first, $second, $third], \array_map('intval', \array_column($media->listByAlbum($albumId), 'id')));

        $media->reorder(albumId: $albumId, orderedIds: [$third, $first, $second]);
        $this->assertSame([$third, $first, $second], \array_map('intval', \array_column($media->listByAlbum($albumId), 'id')));

        $albums->setCover(id: $albumId, mediaId: $third);
        $this->assertSame($third, (int) $albums->getById($albumId)['cover_media_id']);
    }

    public function test_reorder_ignores_an_id_from_another_album(): void
    {
        $media = new Media($this->db);
        $albumId = $this->album();
        $other = $this->album(slug: 'other');
        $mine = $this->photo($albumId, 'a', 1);
        $foreign = $this->photo($other, 'z', 5);

        $media->reorder(albumId: $albumId, orderedIds: [$foreign, $mine]);

        $this->assertSame(5, (int) $media->getById($foreign)['sort_order']);
        $this->assertSame(2, (int) $media->getById($mine)['sort_order']);
    }

    public function test_media_text_type_meta_and_soft_delete(): void
    {
        $media = new Media($this->db);
        $albumId = $this->album();
        $id = (int) $media->create(['albumId' => $albumId, 'fileName' => 'clip', 'originalName' => null, 'type' => 'video', 'source' => 'https://example.com/v', 'meta' => ['poster' => 'clip'], 'width' => null, 'height' => null, 'sortOrder' => 1]);

        $row = $media->getById($id);
        $this->assertSame('video', $row['type']);
        $this->assertSame(['poster' => 'clip'], \json_decode($row['meta'], true));

        $media->updateText(id: $id, caption: 'On stage', alt: 'A speaker on stage');
        $this->assertSame('A speaker on stage', $media->getById($id)['alt']);

        $media->softDelete($id);
        $this->assertSame([], $media->listByAlbum($albumId));
        $this->assertCount(1, $media->listAnyByAlbum($albumId));
        $this->assertTrue($media->fileNameTaken(albumId: $albumId, fileName: 'clip'));
        $media->restore($id);
        $this->assertCount(1, $media->listByAlbum($albumId));
    }

    public function test_deleting_an_album_for_good_removes_all_its_media_rows(): void
    {
        $media = new Media($this->db);
        $albumId = $this->album();
        $this->photo($albumId, 'a', 1);
        $this->photo($albumId, 'b', 2);

        $media->hardDeleteByAlbum($albumId);

        $this->assertSame([], $media->listAnyByAlbum($albumId));
    }
}
