<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumGallery\Models;

use Osmium\Core\Library\OsmiumPDO;

/**
 * What a theme calls to show albums. The service ships no markup and no route: the theme picks the layout.
 *
 *     $gallery = new \Osmium\Services\OsmiumGallery\Models\Gallery($this->osmium->dataSource, $this->osmium->imagePath(...));
 *     $years = $gallery->byYear($gallery->albums());   // [2026 => [album, ...], 2025 => [...]], newest year first
 *     $album = $gallery->album('london-2026');         // the album with its 'media', or null (missing or a draft)
 *
 * Only published albums are returned. An item is an array with id, type, src, srcset, width, height, alt, caption and
 * source. src is the large copy and srcset lists every size as "url 600w", so the browser picks; width and height are
 * src's own size, for the placeholder that stops the page jumping while photos load. alt falls back to the caption, then
 * the album's name, so an image is never unlabelled. type is 'image' now; a later 'video' item is the same shape, with its
 * poster as src and its address in source.
 */
class Gallery
{
    /** @param \Closure(string): string $imagePath $osmium->imagePath(...): an extensionless path in, the best file's address (or '') out */
    public function __construct(private OsmiumPDO $database, private \Closure $imagePath)
    {
    }

    /**
     * @return list<array<string, mixed>> Published albums, newest event first, each with 'cover' (an item, or null for an empty album) and 'year'
     */
    public function albums(): array
    {
        $albums = [];
        foreach ((new Album($this->database))->listPublished() as $row) {
            $items = $this->items($row);
            $albums[] = $this->present(row: $row) + ['cover' => $this->coverOf(row: $row, items: $items)]; // shortcut: one query per album, fine for tens of albums, batch when a site has hundreds
        }

        return $albums;
    }

    /**
     * @return array<string, mixed>|null The album with its 'media' and 'cover', or null when it is missing or not published
     */
    public function album(string $slug): ?array
    {
        $row = (new Album($this->database))->getPublishedBySlug($slug);
        if ($row === null) return null;

        $items = $this->items($row);

        return $this->present(row: $row) + ['cover' => $this->coverOf(row: $row, items: $items), 'media' => $items];
    }

    /**
     * @return list<array<string, mixed>> The items of a published album in display order; empty for a draft or missing album
     */
    public function media(int $albumId): array
    {
        $row = (new Album($this->database))->getById($albumId);
        $hidden = $row === null || !$row['published'];

        return $hidden ? [] : $this->items($row);
    }

    /**
     * Groups albums by the year of their event date, newest year first. Albums with no date go last under 0.
     *
     * @param list<array<string, mixed>> $albums
     * @return array<int, list<array<string, mixed>>>
     */
    public function byYear(array $albums): array
    {
        $years = [];
        foreach ($albums as $album) {
            $years[(int) $album['year']][] = $album;
        }

        \uksort($years, static fn(int $a, int $b): int => ($b === 0 ? -1 : $b) <=> ($a === 0 ? -1 : $a));

        return $years;
    }

    /** @return array<string, mixed> */
    private function present(array $row): array
    {
        $date = (string) ($row['event_date'] ?? '');

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'blurb' => $row['blurb'] ?? null,
            'eventDate' => $date !== '' ? $date : null,
            'year' => $date !== '' ? (int) \substr($date, 0, 4) : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(array $album): array
    {
        $items = [];
        foreach ((new Media($this->database))->listByAlbum((int) $album['id']) as $row) {
            $items[] = $this->item(row: $row, album: $album);
        }

        return $items;
    }

    /** The chosen cover when it is still a live item of the album, else the first item */
    private function coverOf(array $row, array $items): ?array
    {
        foreach ($items as $item) {
            $chosen = $item['id'] === (int) ($row['cover_media_id'] ?? 0);
            if ($chosen) return $item;
        }

        return $items[0] ?? null;
    }

    /** @return array<string, mixed> */
    private function item(array $row, array $album): array
    {
        $meta = \json_decode((string) ($row['meta'] ?? ''), associative: true);
        $isVideo = $row['type'] === 'video';
        $name = $isVideo ? (string) ($meta['poster'] ?? $row['file_name']) : (string) $row['file_name'];
        $width = (int) $row['width'];
        $height = (int) $row['height'];

        $copies = $this->copies(album: (string) $album['slug'], name: $name, width: $width, height: $height);
        $best = $this->bestCopy($copies);
        $label = ($row['alt'] ?: null) ?? ($row['caption'] ?: null) ?? $album['name'];

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'src' => $best['url'] ?? '',
            'srcset' => \implode(', ', \array_map(static fn(array $copy): string => $copy['url'] . ' ' . $copy['width'] . 'w', $copies)),
            'width' => $best['width'] ?? $width,
            'height' => $best['height'] ?? $height,
            'alt' => (string) $label,
            'caption' => $row['caption'] ?? null,
            'source' => $row['source'] ?? null,
        ];
    }

    /**
     * Each size's address and shape, for the sizes that exist on disk
     *
     * @return list<array{url: string, width: int, height: int}>
     */
    private function copies(string $album, string $name, int $width, int $height): array
    {
        $copies = [];
        foreach (GalleryImage::variants(album: $album, name: $name, width: $width, height: $height) as $variant) {
            $url = ($this->imagePath)($variant['path']);
            $missing = $url === '';
            if ($missing) continue;

            $copies[] = ['url' => $url, 'width' => $variant['width'], 'height' => (int) \max(1, \round($variant['width'] * $height / \max(1, $width)))];
        }

        return $copies;
    }

    /** The copy used as src: the widest one up to the large size, since a page rarely wants the extra size unasked */
    private function bestCopy(array $copies): ?array
    {
        $large = GalleryConfig::sizes()['large'];
        $best = null;
        foreach ($copies as $copy) {
            $fits = $copy['width'] <= $large || $best === null;
            if ($fits) $best = $copy;
        }

        return $best;
    }
}
