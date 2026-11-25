SELECT * FROM {TABLE} WHERE deleted_at IS NULL AND published = 1 ORDER BY event_date DESC, sort_order ASC, id ASC
