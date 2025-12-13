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
    
    // Delete the booking
    $stmt = $mysqli->prepare("DELETE FROM mrbs_entry WHERE id = ?");
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        // Also delete related data if needed
        $stmt2 = $mysqli->prepare("DELETE FROM mrbs_groups WHERE entry_id = ?");
        $stmt2->bind_param("i", $booking_id);
        $stmt2->execute();
        $stmt2->close();
        
        $stmt3 = $mysqli->prepare("DELETE FROM mrbs_prepare WHERE entry_id = ?");
        $stmt3->bind_param("i", $booking_id);
        $stmt3->execute();
        $stmt3->close();
        
        // Redirect back with success message
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&success=1&message=" . urlencode('Booking deleted successfully'));
    } else {
        // Redirect back with error
        $room = isset($_GET['room']) ? $_GET['room'] : '';
        $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        header("Location: dashboard.php?room={$room}&date={$date}&error=" . urlencode('Error deleting booking'));
    }
    
    $stmt->close();
    exit();
} else {
    // No ID provided, redirect to dashboard
    header('Location: dashboard.php?error=' . urlencode('Booking ID not provided'));
    exit();
}
?>