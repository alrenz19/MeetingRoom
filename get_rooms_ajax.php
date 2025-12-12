<?php
require_once 'connection.php';

$area_id = isset($_GET['area_id']) ? (int)$_GET['area_id'] : 0;

if ($area_id > 0) {
    $rooms = get_rooms_by_area($area_id);
} else {
    $rooms = get_all_rooms();
}

header('Content-Type: application/json');
echo json_encode($rooms);
?>