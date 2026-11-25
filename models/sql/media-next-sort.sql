SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM {TABLE} WHERE album_id = :album_id
