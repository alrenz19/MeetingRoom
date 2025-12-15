<?php
// delete_booking.php
date_default_timezone_set('Asia/Manila');
require_once 'connection.php';

// Get booking ID from GET parameter
if (isset($_GET['id'])) {
    $booking_id = (int)$_GET['id'];
    
    if (!is_logged_in()) {
        // Redirect back with error
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&error=" . urlencode('User not logged in'));
        exit();
    }
    
    // Check if the user owns this booking
    $current_user = get_current_username();
    
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT create_by FROM mrbs_entry WHERE id = ?");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $stmt->bind_result($create_by);
    $stmt->fetch();
    $stmt->close();
    
    if ($create_by !== $current_user) {
        // Redirect back with error
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&error=" . urlencode('You can only delete your own bookings'));
        exit();
    }
    
    // Get all email recipients for this booking BEFORE deleting
    $recipients = [];
    
    // 1. Get emails from mrbs_groups table
    $stmt = $mysqli->prepare("
        SELECT email FROM mrbs_groups 
        WHERE entry_id = ? AND email IS NOT NULL AND email != ''
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['email']) && !in_array($row['email'], $recipients)) {
            $recipients[] = $row['email'];
        }
    }
    $stmt->close();
    
    // 2. Get organizer's email if available
    $organizer_email = get_user_email_by_username($create_by);
    if ($organizer_email && !in_array($organizer_email, $recipients)) {
        $recipients[] = $organizer_email;
    }
    
    // Send cancellation notification BEFORE deleting
    if (!empty($recipients)) {
        // Convert array to comma-separated string for the function
        $recipient_emails = implode(',', $recipients);
        send_booking_notification($booking_id, 'cancelled', $recipient_emails);
        error_log("Sent cancellation email for booking $booking_id to: " . $recipient_emails);
    } else {
        error_log("No recipients found for booking $booking_id cancellation");
    }
    
    // Start transaction for data deletion
    $mysqli->begin_transaction();
    
    try {
        // Delete related data first
        $stmt2 = $mysqli->prepare("DELETE FROM mrbs_groups WHERE entry_id = ?");
        $stmt2->bind_param("i", $booking_id);
        $stmt2->execute();
        $stmt2->close();
        
        $stmt3 = $mysqli->prepare("DELETE FROM mrbs_prepare WHERE entry_id = ?");
        $stmt3->bind_param("i", $booking_id);
        $stmt3->execute();
        $stmt3->close();
        
        // Delete the main booking
        $stmt = $mysqli->prepare("DELETE FROM mrbs_entry WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        
        if ($deleted === 0) {
            throw new Exception('Booking not found or could not be deleted');
        }
        
        // Commit transaction
        $mysqli->commit();
        
        // Redirect back with success message
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&deleted=1&booking_id=" . $booking_id);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        // Redirect back with error
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&error=" . urlencode('Failed to delete booking: ' . $e->getMessage()));
    }
    
    exit();
} else {
    // No ID provided, redirect to dashboard
    header('Location: dashboard.php?error=' . urlencode('Booking ID not provided'));
    exit();
}
?>