SELECT A.*,
    (SELECT COUNT(*) FROM {PREFIX}gallery_media AS M WHERE M.album_id = A.id AND M.deleted_at IS NULL) AS media_count,
    COALESCE(
        (SELECT C.file_name FROM {PREFIX}gallery_media AS C WHERE C.id = A.cover_media_id AND C.deleted_at IS NULL),
        (SELECT F.file_name FROM {PREFIX}gallery_media AS F WHERE F.album_id = A.id AND F.deleted_at IS NULL ORDER BY F.sort_order ASC, F.id ASC LIMIT 1)
    ) AS cover_file
FROM {TABLE} AS A
WHERE A.deleted_at IS NULL
ORDER BY A.event_date DESC, A.sort_order ASC, A.id ASC
