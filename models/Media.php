<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The items in albums. type is 'image' now; a later 'video' item keeps its address in source and anything else it needs in
 * meta (JSON), with a poster image going through the same image files as a photo.
 */
class Media extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'gallery_media');
    }

    /** An album's live items in display order */
    public function listByAlbum(int $albumId): array
    {
        return $this->byAlbum(file: 'media-list-by-album.sql', albumId: $albumId);
    }

    /** Every item of an album, binned ones included, for permanent delete of the album */
    public function listAnyByAlbum(int $albumId): array
    {
        return $this->byAlbum(file: 'media-list-any-by-album.sql', albumId: $albumId);
    }

    /** An album's binned items, newest binned first */
    public function listDeletedByAlbum(int $albumId): array
    {
        return $this->byAlbum(file: 'media-list-deleted-by-album.sql', albumId: $albumId);
    }

    public function getById(int $id): ?array
    {
        return $this->one(file: 'media-get-by-id.sql', id: $id);
    }

    /** Like getById() but also finds binned items */
    public function getAnyById(int $id): ?array
    {
        return $this->one(file: 'media-get-any.sql', id: $id);
    }

    /** Whether the album already has an item stored under this file name, binned ones included */
    public function fileNameTaken(int $albumId, string $fileName): bool
    {
        $this->database->query($this->loadSqlFile('media-count-file-name.sql'));
        $this->database->bind(param: ':album_id', value: $albumId);
        $this->database->bind(param: ':file_name', value: $fileName);

        return (int) ($this->database->single()['total'] ?? 0) > 0;
    }

    /** The sort_order that puts a new item last */
    public function nextSort(int $albumId): int
    {
        $this->database->query($this->loadSqlFile('media-next-sort.sql'));
        $this->database->bind(param: ':album_id', value: $albumId);

        return (int) ($this->database->single()['next_sort'] ?? 1);
    }

    /**
     * @param array{albumId: int, type?: string, fileName: string, originalName: ?string, source?: ?string, meta?: ?array, caption?: ?string, alt?: ?string, width: ?int, height: ?int, sortOrder: int} $details
     */
    public function create(array $details): int
    {
        $meta = $details['meta'] ?? null;
        $this->database->query($this->loadSqlFile('media-create.sql'));
        $this->database->bind(param: ':album_id', value: $details['albumId']);
        $this->database->bind(param: ':type', value: $details['type'] ?? 'image');
        $this->database->bind(param: ':file_name', value: $details['fileName']);
        $this->database->bind(param: ':original_name', value: $details['originalName']);
        $this->database->bind(param: ':source', value: $details['source'] ?? null);
        $this->database->bind(param: ':meta', value: $meta === null ? null : \json_encode($meta));
        $this->database->bind(param: ':caption', value: $details['caption'] ?? null);
        $this->database->bind(param: ':alt', value: $details['alt'] ?? null);
        $this->database->bind(param: ':width', value: $details['width']);
        $this->database->bind(param: ':height', value: $details['height']);
        $this->database->bind(param: ':sort_order', value: $details['sortOrder']);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    public function updateText(int $id, ?string $caption, ?string $alt): void
    {
        $this->database->query($this->loadSqlFile('media-update-text.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':caption', value: $caption);
        $this->database->bind(param: ':alt', value: $alt);
        $this->database->execute();
    }

    /**
     * Puts an album's items in the order given: the first id gets 1, the next 2, and so on. An id from another album is ignored.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $albumId, array $orderedIds): void
    {
        $position = 1;
        foreach ($orderedIds as $id) {
            $this->database->query($this->loadSqlFile('media-set-sort.sql'));
            $this->database->bind(param: ':id', value: $id);
            $this->database->bind(param: ':album_id', value: $albumId);
            $this->database->bind(param: ':sort_order', value: $position);
            $this->database->execute();
            $position++;
        }
    }

    /** The turn (0, 90, 180 or 270 degrees clockwise) a photo's copies were built with, from its original */
    public function rotation(array $row): int
    {
        $meta = \json_decode((string) ($row['meta'] ?? ''), associative: true);

        return (int) ($meta['rotation'] ?? 0);
    }

    /** Records a photo's new turn and size, keeping anything else held in meta */
    public function setRotation(int $id, int $rotation, int $width, int $height): void
    {
        $row = $this->getAnyById($id);
        $meta = \json_decode((string) ($row['meta'] ?? ''), associative: true);
        $meta = \is_array($meta) ? $meta : [];
        $meta['rotation'] = $rotation;

        $this->database->query($this->loadSqlFile('media-set-rotation.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':width', value: $width);
        $this->database->bind(param: ':height', value: $height);
        $this->database->bind(param: ':meta', value: \json_encode($meta));
        $this->database->execute();
    }

    public function softDelete(int $id): void
    {
        $this->run(file: 'media-soft-delete.sql', id: $id);
    }

    public function restore(int $id): void
    {
        $this->run(file: 'media-restore.sql', id: $id);
    }

    public function hardDelete(int $id): void
    {
        $this->run(file: 'media-hard-delete.sql', id: $id);
    }

    public function hardDeleteByAlbum(int $albumId): void
    {
        $this->database->query($this->loadSqlFile('media-hard-delete-by-album.sql'));
        $this->database->bind(param: ':album_id', value: $albumId);
        $this->database->execute();
    }

    private function byAlbum(string $file, int $albumId): array
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':album_id', value: $albumId);

        return $this->database->resultset();
    }

    private function one(string $file, int $id): ?array
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':id', value: $id);
        $result = $this->database->single();

        return $result ?: null;
    }

    private function run(string $file, int $id): void
    {
        $this->database->query($this->loadSqlFile($file));
        $this->database->bind(param: ':id', value: $id);
        $this->database->execute();
    }
}
