SELECT A.id, A.name, A.deleted_at, CL.user_name AS changed_by, CU.avatar AS changed_by_avatar
FROM {TABLE} AS A
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'gallery_album' AND record_id = A.id)
LEFT JOIN {PREFIX}users AS CU ON CU.id = CL.user_id
WHERE A.deleted_at IS NOT NULL
ORDER BY A.deleted_at DESC
