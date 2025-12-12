<?php
require_once 'connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $room_id = (int)$_POST['room_id'];
    $date = $_POST['date'];
    $event_name = sanitize_input($_POST['event_name']);
    $event_type = $_POST['event_type'];
    $organizer = sanitize_input($_POST['organizer']);
    $description = sanitize_input($_POST['description']);
    $start_time = isset($_POST['start_time']) && !empty($_POST['start_time']) ? $_POST['start_time'] : '08:00';
    
    // Default duration: 1 hour
    $duration = 3600; // 1 hour in seconds
    
    // Calculate timestamps
    $start_datetime = strtotime($date . ' ' . $start_time);
    $end_datetime = $start_datetime + $duration;
    
    // Check for conflicts
    $conflict_check = get_bookings_for_room($room_id, $date, $date);
    $has_conflict = false;
    
    foreach ($conflict_check as $booking) {
        if (($start_datetime < $booking['end_time'] && $end_datetime > $booking['start_time'])) {
            $has_conflict = true;
            break;
        }
    }
    
    if ($has_conflict) {
        $error = "Time slot is no longer available. Please choose another time.";
        header("Location: dashboard.php?date=$date&room=$room_id&error=" . urlencode($error));
        exit();
    }
    
    // Insert booking
    $stmt = $mysqli->prepare("
        INSERT INTO mrbs_entry (
            start_time, end_time, entry_type, room_id, 
            create_by, name, type, description, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $entry_type = 0; // Single event
    $status = 0; // Confirmed
    
    $stmt->bind_param(
        "iiiissssi",
        $start_datetime,
        $end_datetime,
        $entry_type,
        $room_id,
        $organizer,
        $event_name,
        $event_type,
        $description,
        $status
    );
    
    if ($stmt->execute()) {
        $booking_id = $mysqli->insert_id;
        
        // Send success back to dashboard
        header("Location: dashboard.php?date=$date&room=$room_id&success=1&booking_id=$booking_id");
        exit();
    } else {
        $error = "Failed to create booking: " . $mysqli->error;
        header("Location: dashboard.php?date=$date&room=$room_id&error=" . urlencode($error));
        exit();
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>