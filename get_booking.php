<?php
// get_booking.php
date_default_timezone_set('Asia/Manila');
require_once 'connection.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

$booking_id = (int)$_GET['id'];

// Get booking details
$booking = get_booking_for_edit($booking_id);

if ($booking) {
    // Get additional info (representative, preparations, emails)
    global $mysqli;
    
    // Get representative if external event
    $representative = '';
    if ($booking['type'] === 'E') {
        $stmt = $mysqli->prepare("SELECT full_name FROM mrbs_groups WHERE entry_id = ? AND email IS NULL");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->bind_result($rep_name);
        if ($stmt->fetch()) {
            $representative = $rep_name;
        }
        $stmt->close();
    }
    
    // Get notification emails
    $notification_emails = [];
    $stmt = $mysqli->prepare("SELECT email FROM mrbs_groups WHERE entry_id = ? AND email IS NOT NULL");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $stmt->bind_result($email);
    while ($stmt->fetch()) {
        if ($email) {
            $notification_emails[] = $email;
        }
    }
    $stmt->close();
    
    // Add additional data to booking array
    $booking['representative'] = $representative;
    $booking['notification_emails'] = implode(', ', $notification_emails);
    
    echo json_encode(['success' => true, 'booking' => $booking]);
} else {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
}
?>