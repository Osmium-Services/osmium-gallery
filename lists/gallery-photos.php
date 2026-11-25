<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Photo lists on an album's edit page, rendered by ListView. Row fields come from GalleryController::photoRows().
 * Row actions post the photo's id to gallery/action/; Edit fires the list:action event that gallery-edit.js answers
 * with the caption and alt text form.
 */
return [
    'active' => [
        'id' => 'osmium-gallery-photos',
        'title' => 'Photos',
        'noun' => ['photo', 'photos'],
        'endpoint' => 'gallery/action/',
        'note' => 'Shown in this order. The cover is the album\'s picture in lists; an album with no cover uses its first photo.',
        'emptyText' => 'No photos yet - drop some above.',
        'order' => null, // Server order: display order
        'grid' => require __DIR__ . '/../grids/gallery-photos.php',
        'columns' => [
            ['key' => 'thumb_list', 'label' => '', 'type' => 'image', 'altKey' => 'alt', 'sortable' => false],
            ['key' => 'caption', 'label' => 'Caption', 'type' => 'text', 'strong' => true, 'fill' => true, 'fallback' => 'No caption'],
            ['key' => 'alt', 'label' => 'Alt text', 'type' => 'text', 'hideBelow' => 'md'],
            ['key' => 'coverBadge', 'label' => '', 'type' => 'raw', 'nowrap' => true, 'sortable' => false],
        ],
        'actions' => [
            ['label' => 'Edit photo', 'icon' => 'bx-zoom-in', 'event' => 'view_photo'],
            ['label' => 'Edit caption and alt', 'icon' => 'bx-edit-alt', 'event' => 'edit_photo'],
            [
                'label' => 'Make cover',
                'icon' => 'bx-star',
                'action' => 'set_cover',
                'visible' => fn(array $row, array $context): bool => !$row['isCover'],
            ],
            [
                'label' => 'Move earlier',
                'icon' => 'bx-up-arrow-alt',
                'action' => 'move_earlier',
                'visible' => fn(array $row, array $context): bool => !$row['isFirst'],
            ],
            [
                'label' => 'Move later',
                'icon' => 'bx-down-arrow-alt',
                'action' => 'move_later',
                'visible' => fn(array $row, array $context): bool => !$row['isLast'],
            ],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete_media',
                'confirm' => 'Delete this photo? It can be restored from Deleted Photos.',
                'danger' => true,
            ],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'osmium-gallery-photos-deleted',
        'title' => 'Deleted Photos',
        'noun' => ['photo', 'photos'],
        'endpoint' => 'gallery/action/',
        'order' => null,
        'columns' => [
            ['key' => 'thumb_list', 'label' => '', 'type' => 'image', 'altKey' => 'alt', 'sortable' => false],
            ['key' => 'caption', 'label' => 'Caption', 'type' => 'text', 'strong' => true, 'fill' => true, 'fallback' => 'No caption'],
        ],
        'actions' => [
            ['label' => 'Restore', 'icon' => 'bx-undo', 'action' => 'restore_media', 'class' => 'text-success'],
            [
                'label' => 'Permanently Delete',
                'icon' => 'bx-trash',
                'action' => 'permanently_delete_media',
                'confirm' => 'Permanently delete this photo and its original? This cannot be undone!',
                'danger' => true,
                'visible' => fn(array $row, array $context): bool => (bool) $context['isAdmin'],
            ],
        ],
    ]),
];
