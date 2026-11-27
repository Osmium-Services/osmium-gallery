<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Modules\Admin\Services\OsmiumAdmin;
use Osmium\Services\OsmiumGallery\Models\Album;
use Osmium\Services\OsmiumGallery\Models\GalleryImage;
use Osmium\Services\OsmiumGallery\Models\Media;

/**
 * Gallery controller - manages albums and the photos in them.
 *
 * Routes:
 *   - index()  → /admin/gallery/
 *   - add()    → /admin/gallery/add/
 *   - edit()   → /admin/gallery/edit/?id=
 *   - action() → /admin/gallery/action/   (AJAX CRUD; multipart for one photo at a time)
 */
class GalleryController extends AdminController
{
    private const RECORD_TYPE = 'gallery_album';

    // =========================================================================
    // Public Actions
    // =========================================================================

    public function index(): void
    {
        $albums = new Album($this->osmium->dataSource);

        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/gallery.php';
        $this->data['admin']['albums'] = \array_map($this->formatAlbum(...), $albums->listAll());
        $this->data['admin']['recentAlbums'] = \array_map($this->formatRecent(...), $albums->listRecent());
        $this->data['admin']['deletedAlbums'] = \array_map($this->formatDeleted(...), $albums->listDeleted());

        $this->setView('gallery/index.phtml');
    }

    public function add(): void
    {
        $this->osmium->clearStashedQuerystring();
        $this->data['admin']['album'] = ['id' => 0, 'name' => '', 'slug' => '', 'blurb' => '', 'eventDate' => '', 'published' => false, 'coverId' => 0];
        $this->setView('gallery/add.phtml');
    }

    public function edit(): void
    {
        $albums = new Album($this->osmium->dataSource);

        $id = $this->editedAlbumId();
        $row = $id ? $albums->getById($id) : null;
        if (!$row) $this->redirect('gallery/');

        $media = new Media($this->osmium->dataSource);
        $photos = \array_values($media->listByAlbum($id));
        $this->data['admin']['isAdmin'] = $this->isAdmin();
        $this->data['admin']['album'] = $this->formatAlbum($row);
        $this->data['admin']['lists'] = require __DIR__ . '/../lists/gallery-photos.php';
        $this->data['admin']['photos'] = $this->photoRows(photos: $photos, album: $row);
        $this->data['admin']['deletedPhotos'] = $this->photoRows(photos: $media->listDeletedByAlbum($id), album: $row);
        $this->data['admin']['maxUpload'] = OsmiumAdmin::getMaxUploadSize()['formatted'];
        $this->setView('gallery/edit.phtml');
    }

    public function action()
    {
        \header('Content-Type: application/json');

        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if (!$isPost) $this->admin->jsonError('Method not allowed');

        $input = $this->admin->auth->getJsonInput();
        $csrfValid = $this->admin->auth->validateCsrfJson($input);
        if (!$csrfValid) $this->admin->jsonError('Invalid request token. Please refresh and try again.');

        $action = (string) ($input['action'] ?? '');
        $id = (int) ($input['id'] ?? 0);
        $missingParams = $action === '' || ($id === 0 && !\in_array($action, ['create_album', 'purge_deleted'], true));
        if ($missingParams) $this->admin->jsonError('Missing parameters');

        try {
            $this->admin->jsonSuccess($this->dispatch(action: $action, id: $id, input: $input));
        } catch (\Exception $e) {
            $this->admin->jsonError($e->getMessage());
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * The album being edited. Core strips ?id= from the address and keeps it for one read, so a reload (every list action
     * and upload reloads the page) would lose it and bounce to the albums list: the id is remembered in the session instead.
     */
    private function editedAlbumId(): int
    {
        $stashed = (int) $this->osmium->getStashedParam(key: 'id', default: 0); // Core moves query params out of $_GET
        $this->osmium->clearStashedQuerystring();
        if ($stashed > 0) $_SESSION['gallery_edit_album'] = $stashed;

        return (int) ($_SESSION['gallery_edit_album'] ?? 0);
    }

    private function dispatch(string $action, int $id, array $input): array
    {
        return match ($action) {
            'create_album' => $this->createAlbum($input),
            'update_album' => $this->updateAlbum(id: $id, input: $input),
            'publish' => $this->setPublished(id: $id, published: true),
            'unpublish' => $this->setPublished(id: $id, published: false),
            'delete' => $this->deleteAlbum($id),
            'restore' => $this->restoreAlbum($id),
            'permanently_delete' => $this->permanentlyDeleteAlbum($id),
            'purge_deleted' => $this->purgeDeleted(),
            'upload' => $this->upload($id),
            'move_media' => $this->moveMedia(id: $id, afterId: (int) ($input['after'] ?? 0), beforeId: (int) ($input['before'] ?? 0)),
            'set_cover' => $this->setCover($id),
            'rotate_media' => $this->rotateMedia(id: $id, degrees: (int) ($input['degrees'] ?? 0)),
            'update_media' => $this->updateMedia(id: $id, input: $input),
            'delete_media' => $this->deleteMedia($id),
            'restore_media' => $this->restoreMedia($id),
            'permanently_delete_media' => $this->permanentlyDeleteMedia($id),
            default => throw new \InvalidArgumentException('Unknown action'),
        };
    }

    // ----- Albums -----

    private function createAlbum(array $input): array
    {
        $details = $this->validateAlbum($input);
        $albums = new Album($this->osmium->dataSource);
        $details['slug'] = $this->freeSlug(albums: $albums, name: $details['name']);
        $details['published'] = !empty($input['published']);

        $id = $albums->create($details);
        $this->logChange(action: 'Created', id: $id, name: $details['name']);

        return ['success' => true, 'id' => $id];
    }

    /** The address name stays as created: it is the photos' folder and what a theme asks for, so it never changes with the name */
    private function updateAlbum(int $id, array $input): array
    {
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getById($id);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $details = $this->validateAlbum($input);
        $details['slug'] = $album['slug'];
        $albums->update(id: $id, details: $details);
        $this->logChange(action: 'Updated', id: $id, name: $details['name']);

        return ['success' => true, 'id' => $id];
    }

    private function setPublished(int $id, bool $published): array
    {
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getById($id);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $albums->setPublished(id: $id, published: $published);
        $this->logChange(action: $published ? 'Published' : 'Unpublished', id: $id, name: $album['name']);

        return ['success' => true, 'id' => $id];
    }

    private function deleteAlbum(int $id): array
    {
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getById($id);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $albums->softDelete($id);
        $this->logChange(action: 'Deleted', id: $id, name: $album['name']);

        return ['success' => true, 'id' => $id];
    }

    private function restoreAlbum(int $id): array
    {
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getAnyById($id);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $albums->restore($id);
        $this->logChange(action: 'Restored', id: $id, name: $album['name']);

        return ['success' => true, 'id' => $id];
    }

    /** Administrators only: the album's row, every item's row, and the original and every copy of each photo go for good */
    private function permanentlyDeleteAlbum(int $id): array
    {
        $this->requireAdmin();
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getAnyById($id);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $this->logChange(action: 'Permanently deleted', id: $id, name: $album['name']);
        $this->eraseAlbum(album: $album, albums: $albums);

        return ['success' => true, 'id' => $id];
    }

    private function purgeDeleted(): array
    {
        $this->requireAdmin();
        $albums = new Album($this->osmium->dataSource);
        $binned = $albums->listDeleted();
        foreach ($binned as $row) {
            $album = $albums->getAnyById((int) $row['id']);
            $this->eraseAlbum(album: $album, albums: $albums);
        }

        $count = \count($binned);
        $this->admin->model->changelog->log(description: "Purged {$count} deleted album(s)", recordType: self::RECORD_TYPE, recordId: 0);

        return ['success' => true, 'count' => $count];
    }

    private function eraseAlbum(array $album, Album $albums): void
    {
        GalleryImage::removeAlbum(album: $album['slug'], root: $this->root());
        (new Media($this->osmium->dataSource))->hardDeleteByAlbum((int) $album['id']);
        $albums->hardDelete((int) $album['id']);
    }

    // ----- Photos -----

    /** One photo per request, so a bulk upload can say which file was refused and why */
    private function upload(int $albumId): array
    {
        $album = (new Album($this->osmium->dataSource))->getById($albumId);
        if (!$album) throw new \InvalidArgumentException('Album not found');

        $file = $this->checkedUpload();
        $media = new Media($this->osmium->dataSource);
        $name = GalleryImage::name(album: $album['slug'], originalName: $file['name'], root: $this->root());
        $size = GalleryImage::save(sourcePath: $file['tmp_name'], album: $album['slug'], name: $name, root: $this->root());

        $id = $media->create([
            'albumId' => $albumId, 'fileName' => $name, 'originalName' => \substr($file['name'], 0, 255),
            'width' => $size['width'], 'height' => $size['height'], 'sortOrder' => $media->nextSort($albumId),
        ]);
        $this->coverIfNone(album: $album, mediaId: $id);
        $this->logChange(action: 'Added photo ' . $name . ' to', id: $albumId, name: $album['name']);

        return ['success' => true, 'item' => $this->formatMedia(item: (array) $media->getById($id), album: $album)];
    }

    /** @return array{tmp_name: string, name: string} */
    private function checkedUpload(): array
    {
        $file = $_FILES['image'] ?? null;
        $noFileSent = $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        if ($noFileSent) throw new \InvalidArgumentException('No file was sent');

        $uploadFailed = $file['error'] !== UPLOAD_ERR_OK;
        if ($uploadFailed) throw new \InvalidArgumentException('The file did not upload. It may be bigger than the server allows');

        $maxUpload = OsmiumAdmin::getMaxUploadSize();
        $tooLarge = $file['size'] > $maxUpload['bytes'];
        if ($tooLarge) throw new \InvalidArgumentException('The file is too large. Maximum ' . $maxUpload['formatted']);

        return ['tmp_name' => $file['tmp_name'], 'name' => (string) ($file['name'] ?? '')];
    }

    private function coverIfNone(array $album, int $mediaId): void
    {
        $hasCover = !empty($album['cover_media_id']);
        if ($hasCover) return;

        (new Album($this->osmium->dataSource))->setCover(id: (int) $album['id'], mediaId: $mediaId);
    }

    /** Swaps a photo with its neighbour in the album's display order */
    /**
     * Puts a photo straight after $afterId, or straight before $beforeId when there is no photo to go after (dragged to the
     * top of a page or a search result, where the photo before it is out of view). Neither: first in the album.
     */
    private function moveMedia(int $id, int $afterId, int $beforeId): array
    {
        $media = new Media($this->osmium->dataSource);
        $item = $media->getById($id);
        if (!$item) throw new \InvalidArgumentException('Photo not found');

        $ids = \array_map('intval', \array_column($media->listByAlbum((int) $item['album_id']), 'id'));
        $anchor = $afterId ?: $beforeId;
        if ($anchor !== 0 && !\in_array($anchor, $ids, true)) throw new \InvalidArgumentException('Photo not found');
        if ($anchor === $id) return ['success' => true];

        $ids = \array_values(\array_diff($ids, [$id]));
        $position = 0;
        if ($anchor !== 0) $position = (int) \array_search($anchor, $ids, true) + ($afterId ? 1 : 0);
        \array_splice($ids, $position, 0, [$id]);
        $media->reorder(albumId: (int) $item['album_id'], orderedIds: $ids);

        return ['success' => true];
    }

    private function setCover(int $mediaId): array
    {
        $item = (new Media($this->osmium->dataSource))->getById($mediaId);
        if (!$item) throw new \InvalidArgumentException('Photo not found');

        (new Album($this->osmium->dataSource))->setCover(id: (int) $item['album_id'], mediaId: $mediaId);

        return ['success' => true];
    }

    private function updateMedia(int $id, array $input): array
    {
        $media = new Media($this->osmium->dataSource);
        $item = $media->getById($id);
        if (!$item) throw new \InvalidArgumentException('Photo not found');

        $caption = $this->text(value: $input['caption'] ?? '', limit: 255);
        $alt = $this->text(value: $input['alt'] ?? '', limit: 255);
        $media->updateText(id: $id, caption: $caption, alt: $alt);

        return ['success' => true, 'id' => $id];
    }

    /** Turns a photo clockwise by 90, 180 or 270 degrees: every copy is rebuilt from the untouched original */
    private function rotateMedia(int $id, int $degrees): array
    {
        $allowed = \in_array($degrees, [90, 180, 270], true);
        if (!$allowed) throw new \InvalidArgumentException('Turn a photo by 90, 180 or 270 degrees');

        $media = new Media($this->osmium->dataSource);
        $item = $media->getById($id);
        $album = $item ? (new Album($this->osmium->dataSource))->getById((int) $item['album_id']) : null;
        if (!$album) throw new \InvalidArgumentException('Photo not found');

        $total = ($media->rotation($item) + $degrees) % 360;
        $size = GalleryImage::rebuild(album: $album['slug'], name: $item['file_name'], root: $this->root(), rotation: $total);
        $media->setRotation(id: $id, rotation: $total, width: $size['width'], height: $size['height']);
        $this->logChange(action: 'Turned photo ' . $item['file_name'] . ' in', id: (int) $album['id'], name: $album['name']);

        return ['success' => true, 'id' => $id];
    }

    private function deleteMedia(int $id): array
    {
        $media = new Media($this->osmium->dataSource);
        $item = $media->getById($id);
        if (!$item) throw new \InvalidArgumentException('Photo not found');

        $media->softDelete($id);
        $this->unsetCoverIfThis(item: $item);

        return ['success' => true, 'id' => $id];
    }

    private function restoreMedia(int $id): array
    {
        $media = new Media($this->osmium->dataSource);
        $item = $media->getAnyById($id);
        if (!$item) throw new \InvalidArgumentException('Photo not found');

        $media->restore($id);

        return ['success' => true, 'id' => $id];
    }

    /** Administrators only: the row, the original and every copy go for good */
    private function permanentlyDeleteMedia(int $id): array
    {
        $this->requireAdmin();
        $media = new Media($this->osmium->dataSource);
        $item = $media->getAnyById($id);
        $album = $item ? (new Album($this->osmium->dataSource))->getAnyById((int) $item['album_id']) : null;
        if (!$album) throw new \InvalidArgumentException('Photo not found');

        $this->logChange(action: 'Permanently deleted photo ' . $item['file_name'] . ' from', id: (int) $album['id'], name: $album['name']);
        GalleryImage::remove(album: $album['slug'], name: $item['file_name'], root: $this->root());
        $this->unsetCoverIfThis(item: $item);
        $media->hardDelete($id);

        return ['success' => true, 'id' => $id];
    }

    /** An album whose cover photo is removed falls back to having none, which a theme reads as its first photo */
    private function unsetCoverIfThis(array $item): void
    {
        $albums = new Album($this->osmium->dataSource);
        $album = $albums->getAnyById((int) $item['album_id']);
        $isCover = $album && (int) $album['cover_media_id'] === (int) $item['id'];
        if ($isCover) $albums->setCover(id: (int) $album['id'], mediaId: null);
    }

    // ----- Validation and formatting -----

    /**
     * @return array{name: string, slug: string, blurb: ?string, eventDate: ?string, showId: ?int, sortOrder: int}
     */
    private function validateAlbum(array $input): array
    {
        $name = \trim((string) ($input['name'] ?? ''));
        $badName = $name === '' || \mb_strlen($name) > 150;
        if ($badName) throw new \InvalidArgumentException('Name is required and limited to 150 characters');

        $date = $this->text(value: $input['event_date'] ?? '', limit: 10);
        $parsed = $date === null ? null : \DateTime::createFromFormat('!Y-m-d', $date);
        $badDate = $date !== null && ($parsed === false || $parsed->format('Y-m-d') !== $date);
        if ($badDate) throw new \InvalidArgumentException('The date must be a real date');

        return [
            'name' => $name,
            'slug' => '',
            'blurb' => $this->text(value: $input['blurb'] ?? '', limit: 5000),
            'eventDate' => $date,
            'showId' => null,
            'sortOrder' => \max(0, (int) ($input['sort_order'] ?? 0)),
        ];
    }

    private function freeSlug(Album $albums, string $name): string
    {
        $base = GalleryImage::slug($name) ?: 'album';
        $slug = $base;
        for ($number = 2; $albums->slugTaken($slug); $number++) {
            $slug = $base . '-' . $number;
        }

        return $slug;
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = \trim((string) $value);
        $tooLong = \mb_strlen($text) > $limit;
        if ($tooLong) throw new \InvalidArgumentException("Text is limited to {$limit} characters");

        return $text === '' ? null : $text;
    }

    private function requireAdmin(): void
    {
        $notAdmin = !$this->isAdmin();
        if ($notAdmin) throw new \Exception('Only administrators can permanently delete');
    }

    private function root(): string
    {
        return (string) $_SERVER['DOCUMENT_ROOT'];
    }

    private function formatAlbum(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'blurb' => $row['blurb'] ?? '',
            'eventDate' => $row['event_date'] ?? '',
            'published' => (bool) $row['published'],
            'coverId' => (int) ($row['cover_media_id'] ?? 0),
            'photoCount' => (int) ($row['media_count'] ?? 0),
            'itemCountText' => (int) ($row['media_count'] ?? 0) . ((int) ($row['media_count'] ?? 0) === 1 ? ' item' : ' items'),
            'sortOrder' => (int) $row['sort_order'],
            'thumb_list' => $this->albumThumb(row: $row, size: 'thumb'),
            'thumb_grid' => $this->albumThumb(row: $row, size: 'medium'),
            'edit_url' => $this->getBasePath() . 'gallery/edit/?id=' . (int) $row['id'],
            'statusBadge' => $this->statusBadge((bool) $row['published']),
        ];
    }

    /** The list thumbnail: the cover, or the first photo, from the album's thumb copies; blank for an empty album */
    private function albumThumb(array $row, string $size): string
    {
        $noCover = empty($row['cover_file']);
        if ($noCover) return '';

        return $this->stamped($this->osmium->imagePath('/gallery/' . $row['slug'] . '/' . $size . '/' . $row['cover_file']));
    }

    /** An image address with its file's modified time, because images are cached for a year and a rebuilt photo must reload */
    private function stamped(string $url): string
    {
        $mtime = $url === '' ? false : @\filemtime($this->root() . $url);

        return $mtime === false ? $url : $url . '?v=' . $mtime;
    }

    /**
     * @param list<array<string, mixed>> $photos
     * @return list<array<string, mixed>> Rows for the photos lists, 
     */
    private function photoRows(array $photos, array $album): array
    {
        $rows = [];
        foreach ($photos as $item) {
            $row = $this->formatMedia(item: $item, album: $album);
            $row['dragHandle'] = '<span class="drag-handle" title="Drag to reorder"><i class="bx bx-menu"></i></span>';
            $row['coverBadge'] = $row['isCover'] ? '<span class="badge bg-label-primary">Cover</span>' : '';
            $rows[] = $row;
        }

        return $rows;
    }

    private function formatMedia(array $item, array $album): array
    {
        $thumb = $this->stamped($this->osmium->imagePath('/gallery/' . $album['slug'] . '/thumb/' . $item['file_name']));

        return [
            'id' => (int) $item['id'],
            'fileName' => $item['file_name'],
            'originalName' => $item['original_name'] ?? '',
            'caption' => $item['caption'] ?? '',
            'alt' => $item['alt'] ?? '',
            'width' => (int) $item['width'],
            'height' => (int) $item['height'],
            'thumbUrl' => $thumb,
            'viewUrl' => $this->stamped($this->osmium->imagePath('/gallery/' . $album['slug'] . '/large/' . $item['file_name'])),
            'thumb_list' => $thumb,
            'thumb_grid' => $this->stamped($this->osmium->imagePath('/gallery/' . $album['slug'] . '/medium/' . $item['file_name'])) ?: $thumb,
            'isCover' => (int) ($album['cover_media_id'] ?? 0) === (int) $item['id'],
        ];
    }

    private function formatRecent(array $row): array
    {
        $changed = \max((string) ($row['updated_at'] ?? ''), (string) ($row['deleted_at'] ?? ''), (string) $row['created_at']);

        return [
            'id' => (int) $row['id'],
            'deleted_at' => $row['deleted_at'] ?? null,
            'name' => $row['name'],
            'updatedDate' => \date('j M Y, g:ia', \strtotime($changed)),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function formatDeleted(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'deletedDate' => \date('d M Y', \strtotime((string) $row['deleted_at'])),
            'changed_by' => $row['changed_by'] ?? null,
            'changed_by_avatar' => $row['changed_by_avatar'] ?? null,
        ];
    }

    private function statusBadge(bool $published): string
    {
        return $published ? '<span class="badge bg-label-success">Published</span>' : '<span class="badge bg-label-secondary">Draft</span>';
    }

    private function logChange(string $action, int $id, string $name): void
    {
        $this->admin->model->changelog->log(
            description: $action . ': ' . $name,
            recordType: self::RECORD_TYPE,
            recordId: $id,
        );
    }
}
