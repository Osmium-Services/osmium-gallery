<?php

declare(strict_types=1);

/**
 * Grid (card) layout for the Albums list, rendered by Osmium\Modules\Admin\Core\ListViewGrid. Columns use the same
 * vocabulary as lists/gallery.php but nothing is shared with it. The actions are a copy of the list's.
 */
return [
    'columns' => 3,
    'imageHeight' => 160,
    'image' => ['key' => 'thumb_grid', 'type' => 'image', 'altKey' => 'name'],
    'title' => ['key' => 'name', 'type' => 'text', 'strong' => true],
    'meta' => [
        ['key' => 'statusBadge', 'type' => 'raw'],
        ['key' => 'eventDate', 'type' => 'text', 'small' => true],
        ['key' => 'photoCount', 'type' => 'text', 'small' => true],
    ],
    'actionsAt' => 'bottom-end',
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
];
