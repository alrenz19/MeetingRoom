<?php
require 'admin_meetings_helpers.php';
header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['status'=>'error']); exit; }

$sql = "SELECT e.id, e.name, u.display_name AS organizer 
        FROM mrbs_entry e
        JOIN mrbs_users u ON u.name = e.create_by
        WHERE e.id = ?";
$res = db_query_all($sql, [$id]);

if ($res) {
    echo json_encode($res[0]);
} else {
    echo json_encode(['status'=>'error']);
}
