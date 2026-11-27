<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Photos list, rendered by Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same
 * vocabulary as lists/gallery-photos.php but nothing is shared with it. The actions are a copy of the list's.
 */
return [
    'columns' => 5,
    'imageHeight' => 150,
    'image' => ['key' => 'thumb_grid', 'type' => 'image', 'altKey' => 'alt'],
    'title' => ['key' => 'caption', 'type' => 'text', 'strong' => true, 'fallback' => 'No caption'],
    'meta' => [
        ['key' => 'dragHandle', 'type' => 'raw'],
        ['key' => 'coverBadge', 'type' => 'raw'],
    ],
    'actionsAt' => 'bottom-end',
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
            'label' => 'Delete',
            'icon' => 'bx-trash',
            'action' => 'delete_media',
            'confirm' => 'Delete this photo? It can be restored from Deleted Photos.',
            'danger' => true,
        ],
    ],
];
