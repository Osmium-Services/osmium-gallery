SELECT A.id, A.name, A.created_at, A.updated_at, A.deleted_at, CL.user_name AS changed_by, CU.avatar AS changed_by_avatar
FROM {TABLE} AS A
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'gallery_album' AND record_id = A.id)
LEFT JOIN {PREFIX}users AS CU ON CU.id = CL.user_id
WHERE (A.created_at >= NOW() - INTERVAL 24 HOUR OR A.updated_at >= NOW() - INTERVAL 24 HOUR OR A.deleted_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(A.updated_at, A.created_at), A.created_at, COALESCE(A.deleted_at, A.created_at)) DESC, A.id DESC
LIMIT 10
