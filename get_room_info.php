<?php
// get_room_info.php
require_once 'connection.php';

// Set JSON header
header('Content-Type: application/json');

if (isset($_GET['room_id'])) {
    $room_id = (int)$_GET['room_id'];
    get_room_info_json($room_id);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Room ID is required'
    ]);
}
?>