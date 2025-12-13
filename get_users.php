<?php
// get_users.php
date_default_timezone_set('Asia/Manila');
require_once 'connection.php';

header('Content-Type: application/json');

$users = get_users_with_email();
echo json_encode(['users' => $users]);
?>