<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Models;

use Osmium\Core\Library\OsmiumPDO;
use Osmium\Core\Models\Model;

/**
 * The albums an admin manages. An album is soft-deleted first and only removed for good from the bin.
 */
class Album extends Model
{
    protected ?string $sqlDir = __DIR__ . '/sql';

    public function __construct(OsmiumPDO $database)
    {
        parent::__construct(database: $database, tableName: 'gallery_albums');
    }

    /** Every live album, newest event first, with how many live items it holds */
    public function listAll(): array
    {
        return $this->rows('album-list-all.sql');
    }

    public function listPublished(): array
    {
        return $this->rows('album-list-published.sql');
    }

    public function listRecent(): array
    {
        return $this->rows('album-list-recent.sql');
    }

    /** Binned albums with who last changed each, for the bin list; getAnyById() has the rest */
    public function listDeleted(): array
    {
        return $this->rows('album-list-deleted.sql');
    }

    public function getById(int $id): ?array
    {
        return $this->one(file: 'album-get-by-id.sql', id: $id);
    }

    /** Like getById() but also finds binned albums, for restore and permanent delete */
    public function getAnyById(int $id): ?array
    {
        return $this->one(file: 'album-get-any.sql', id: $id);
    }

    public function getPublishedBySlug(string $slug): ?array
    {
        $this->database->query($this->loadSqlFile('album-get-published-by-slug.sql'));
        $this->database->bind(param: ':slug', value: $slug);
        $result = $this->database->single();

        return $result ?: null;
    }

    /** Whether another album, binned ones included, already uses the slug (the column is unique) */
    public function slugTaken(string $slug, int $exceptId = 0): bool
    {
        $this->database->query($this->loadSqlFile('album-count-slug.sql'));
        $this->database->bind(param: ':slug', value: $slug);
        $this->database->bind(param: ':id', value: $exceptId);

        return (int) ($this->database->single()['total'] ?? 0) > 0;
    }

    /**
     * @param array{name: string, slug: string, blurb: ?string, eventDate: ?string, showId: ?int, sortOrder: int, published: bool} $details
     */
    public function create(array $details): int
    {
        $this->database->query($this->loadSqlFile('album-create.sql'));
        $this->bindDetails($details);
        $this->database->bind(param: ':published', value: $details['published'] ? 1 : 0);
        $this->database->execute();

        return (int) $this->database->lastInsertId();
    }

    /**
     * @param array{name: string, slug: string, blurb: ?string, eventDate: ?string, showId: ?int, sortOrder: int} $details
     */
    public function update(int $id, array $details): void
    {
        $this->database->query($this->loadSqlFile('album-update.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->bindDetails($details);
        $this->database->execute();
    }

    public function setCover(int $id, ?int $mediaId): void
    {
        $this->database->query($this->loadSqlFile('album-set-cover.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':cover_media_id', value: $mediaId);
        $this->database->execute();
    }

    public function setPublished(int $id, bool $published): void
    {
        $this->database->query($this->loadSqlFile('album-set-published.sql'));
        $this->database->bind(param: ':id', value: $id);
        $this->database->bind(param: ':published', value: $published ? 1 : 0);
        $this->database->execute();
    }

    public function softDelete(int $id): void
    {
        $this->run(file: 'album-soft-delete.sql', id: $id);
    }

    public function restore(int $id): void
    {
        $this->run(file: 'album-restore.sql', id: $id);
    }

    public function hardDelete(int $id): void
    {
        $this->run(file: 'album-hard-delete.sql', id: $id);
    }

    private function rows(string $file): array
    {
        $this->database->query($this->loadSqlFile($file));

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

    private function bindDetails(array $details): void
    {
        $this->database->bind(param: ':name', value: $details['name']);
        $this->database->bind(param: ':slug', value: $details['slug']);
        $this->database->bind(param: ':blurb', value: $details['blurb']);
        $this->database->bind(param: ':event_date', value: $details['eventDate']);
        $this->database->bind(param: ':show_id', value: $details['showId']);
        $this->database->bind(param: ':sort_order', value: $details['sortOrder']);
    }
}
