<?php

declare(strict_types=1);

use Osmium\Modules\Admin\Core\ListView;

/**
 * Gallery lists, rendered by Osmium\Modules\Admin\Core\ListView. Row fields (thumb_list, statusBadge...) are prepared
 * by GalleryController::formatAlbum(), formatRecent() and formatDeleted().
 */
require_once 'app/core/admin/services/ChangedByCell.php';

$changedByColumn = ['key' => 'changed_by', 'label' => '', 'type' => 'html', 'sortable' => false, 'render' => fn(array $row, array $context): string => \Osmium\Modules\Admin\Services\ChangedByCell::html($row, (string)($context['avatarUrl'] ?? ''))];

return [
    'active' => [
        'id' => 'osmium-gallery',
        'title' => 'Albums',
        'noun' => ['album', 'albums'],
        'endpoint' => 'gallery/action/',
        'note' => 'Albums of photos a theme can show. A draft album is not shown on the site until it is published.',
        'emptyText' => 'No albums yet - use Add Album to create one.',
        'order' => null, // Server order: newest event first
        'grid' => require __DIR__ . '/../grids/gallery.php',
        'columns' => [
            ['key' => 'thumb_list', 'label' => '', 'type' => 'image', 'altKey' => 'name', 'sortable' => false, 'hideBelow' => 'md'],
            ['key' => 'name', 'label' => 'Album', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'eventDate', 'label' => 'Date', 'type' => 'text', 'nowrap' => true, 'hideBelow' => 'md'],
            ['key' => 'photoCount', 'label' => 'Photos', 'type' => 'text', 'nowrap' => true, 'hideBelow' => 'md'],
            ['key' => 'statusBadge', 'label' => 'Status', 'type' => 'raw', 'nowrap' => true],
        ],
        'actions' => [
            ['label' => 'Edit', 'icon' => 'bx-edit-alt', 'url' => '{edit_url}'],
            [
                'label' => 'Publish',
                'icon' => 'bx-show',
                'action' => 'publish',
                'visible' => fn(array $row, array $context): bool => !$row['published'],
            ],
            [
                'label' => 'Unpublish',
                'icon' => 'bx-hide',
                'action' => 'unpublish',
                'visible' => fn(array $row, array $context): bool => (bool) $row['published'],
            ],
            [
                'label' => 'Delete',
                'icon' => 'bx-trash',
                'action' => 'delete',
                'confirm' => 'Delete this album? It can be restored from Deleted Albums.',
                'danger' => true,
            ],
        ],
    ],

    'recent' => [
        'id' => 'osmium-gallery-recent',
        'title' => 'Recent Changes',
        'noun' => ['album', 'albums'],
        'icon' => 'bx-time-five',
        'rowClass' => static fn(array $row): string => empty($row['deleted_at']) ? '' : 'table-danger',
        'endpoint' => 'gallery/action/',
        'collapsible' => ['label' => 'Recent Changes ({count})'],
        'hideWhenEmpty' => false,
        'columns' => [
            $changedByColumn,
            ['key' => 'name', 'label' => 'Album', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'updatedDate', 'label' => 'Updated', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ],

    'deleted' => ListView::deletedPreset([
        'id' => 'osmium-gallery-deleted',
        'title' => 'Deleted Albums',
        'noun' => ['item', 'items'],
        'endpoint' => 'gallery/action/',
        'hideWhenEmpty' => false,
        'headerButton' => [
            'label' => 'Purge All',
            'icon' => 'bx-trash',
            'class' => 'btn-outline-danger',
            'action' => 'purge_deleted',
            'confirm' => 'Permanently delete all deleted albums, with every photo and its original? This cannot be undone!',
            'visible' => fn(array $rows, array $context): bool => (bool) $context['isAdmin'],
        ],
        'columns' => [
            $changedByColumn,
            ['key' => 'name', 'label' => 'Album', 'type' => 'text', 'strong' => true, 'fill' => true],
            ['key' => 'deletedDate', 'label' => 'Deleted', 'type' => 'text', 'small' => true, 'nowrap' => true],
        ],
    ]),
];
