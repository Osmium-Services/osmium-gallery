SELECT * FROM {TABLE} WHERE album_id = :album_id AND deleted_at IS NOT NULL ORDER BY deleted_at DESC
