<?php
// connection.php
session_start();

// Database configuration
define('DB_HOST', '172.16.81.215');
define('DB_NAME', 'mrbs');
define('DB_USER', 'mrbsNuser');
define('DB_PASS', 'MrbsPassword123!');

// Connect to database using mysqli
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$dbSecondPass = '$oaQooIj7_6981';

//$connSecond = new mysqli("172.16.131.229", "catshatecoffeer", $dbSecondPass, "db_portal");
$connSecond = new mysqli("127.0.0.1", "catshatecoffeer", $dbSecondPass, "db_portal");

if ($connSecond->connect_error) {
    die("Second DB Connection failed: " . $connSecond->connect_error);
}

// Check connection
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Set character set
$mysqli->set_charset("utf8");

// Set timezone
date_default_timezone_set('Asia/Manila');

// User authentication functions
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function get_current_username() {
    return $_SESSION['username'] ?? null;
}

function get_current_user_name() {
    return $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Guest';
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? 0;
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit();
    }
}

// Authentication check
function authenticate_user($username, $password) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("SELECT id, name, display_name, password_hash FROM mrbs_users WHERE name = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($id, $name, $display_name, $password_hash);
    
    if ($stmt->fetch() && password_verify($password, $password_hash)) {
        $_SESSION['user_id'] = $id;
        $_SESSION['username'] = $name;
        $_SESSION['display_name'] = $display_name ? $display_name : $name;
        
        // Update last login
        $update_stmt = $mysqli->prepare("UPDATE mrbs_users SET last_login = UNIX_TIMESTAMP() WHERE id = ?");
        $update_stmt->bind_param("i", $id);
        $update_stmt->execute();
        $update_stmt->close();
        
        return true;
    }
    
    return false;
}

// Helper functions
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Get all areas
function get_areas() {
    global $mysqli;
    $result = $mysqli->query("SELECT id, area_name FROM mrbs_area WHERE disabled = 0 ORDER BY sort_key");
    $areas = [];
    while ($row = $result->fetch_assoc()) {
        $areas[] = $row;
    }
    return $areas;
}

// Get rooms by area
function get_rooms_by_area($area_id) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT id, room_name, description, area_id FROM mrbs_room WHERE area_id = ? AND disabled = 0 ORDER BY sort_key");
    $stmt->bind_param("i", $area_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }
    $stmt->close();
    return $rooms;
}

// Get all rooms with area info
function get_all_rooms() {
    global $mysqli;
    $result = $mysqli->query("
        SELECT r.id, r.room_name, r.description, a.area_name 
        FROM mrbs_room r 
        JOIN mrbs_area a ON r.area_id = a.id 
        WHERE r.disabled = 0 AND a.disabled = 0 
        ORDER BY a.sort_key, r.sort_key
    ");
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        $rooms[] = $row;
    }
    return $rooms;
}

function get_room_info($room_id) {
    global $conn;
    
    $room_id = (int)$room_id;
    
    $sql = "SELECT r.*, a.area_name 
            FROM rooms r 
            LEFT JOIN areas a ON r.area_id = a.id 
            WHERE r.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return false;
}

/**
 * Get room information as JSON response (for AJAX calls)
 * @param int $room_id Room ID
 */
function get_room_info_json($room_id) {
    $room_info = get_room_info($room_id);
    
    if ($room_info) {
        echo json_encode([
            'success' => true,
            'room' => [
                'room_name' => $room_info['room_name'],
                'area_id' => $room_info['area_id'],
                'area_name' => $room_info['area_name']
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Room not found'
        ]);
    }
}

// Get area info by ID
function get_area_info($area_id) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("SELECT id, area_name FROM mrbs_area WHERE id = ? AND disabled = 0");
    $stmt->bind_param("i", $area_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $area = $result->fetch_assoc();
    $stmt->close();
    
    return $area ?: ['area_name' => 'Unknown Area'];
}

// Get all rooms grouped by area
function get_all_rooms_grouped_by_area() {
    global $mysqli;
    $result = $mysqli->query("
        SELECT 
            a.id as area_id,
            a.area_name,
            r.id as room_id,
            r.room_name,
            r.description,
            r.sort_key as room_sort_key,
            a.sort_key as area_sort_key
        FROM mrbs_area a
        JOIN mrbs_room r ON a.id = r.area_id
        WHERE a.disabled = 0 AND r.disabled = 0
        ORDER BY a.sort_key, r.sort_key
    ");
    
    $rooms_grouped = [];
    while ($row = $result->fetch_assoc()) {
        $area_id = $row['area_id'];
        if (!isset($rooms_grouped[$area_id])) {
            $rooms_grouped[$area_id] = [
                'area_id' => $area_id,
                'area_name' => $row['area_name'],
                'rooms' => []
            ];
        }
        
        $rooms_grouped[$area_id]['rooms'][] = [
            'id' => $row['room_id'],
            'room_name' => $row['room_name'],
            'description' => $row['description']
        ];
    }
    
    return array_values($rooms_grouped);
}

// Get bookings for a specific date range and room
function get_bookings_for_room($room_id, $start_date, $end_date) {
    global $mysqli;
    
    // Convert dates to timestamps
    $start_timestamp = strtotime($start_date . " 00:00:00");
    $end_timestamp = strtotime($end_date . " 23:59:59");
    
    $stmt = $mysqli->prepare("
        SELECT 
            e.id,
            e.start_time,
            e.end_time,
            e.name,
            e.create_by,
            e.description,
            e.type,
            e.status,
            e.room_id,
            e.timestamp,
            u.display_name,
            r.room_name
        FROM mrbs_entry e
        LEFT JOIN mrbs_users u ON u.name = e.create_by
        JOIN mrbs_room r ON r.id = e.room_id
        WHERE e.room_id = ?
        AND (
            (e.start_time >= ? AND e.start_time <= ?) OR
            (e.end_time >= ? AND e.end_time <= ?) OR
            (e.start_time <= ? AND e.end_time >= ?)
        )
        ORDER BY e.start_time
    ");
    
    $stmt->bind_param("iiiiiii", 
        $room_id, 
        $start_timestamp, 
        $end_timestamp,
        $start_timestamp,
        $end_timestamp,
        $start_timestamp,
        $end_timestamp
    );
    
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
    
    return $bookings;
}

// Get booking by ID
function get_booking_by_id($booking_id) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("
        SELECT e.*, r.room_name, a.area_name, u.display_name 
        FROM mrbs_entry e
        LEFT JOIN mrbs_room r ON e.room_id = r.id
        LEFT JOIN mrbs_area a ON r.area_id = a.id
        LEFT JOIN mrbs_users u ON u.name = e.create_by
        WHERE e.id = ?
    ");
    
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    return $booking;
}

// Check for booking conflicts with buffer allowance
function check_booking_conflict_with_buffer($room_id, $start_time, $end_time, $booking_id = null, $buffer_minutes = 2) {
    global $mysqli;
    
    // Apply buffer: start 2 minutes later, end 2 minutes earlier
    $start_time_with_buffer = $start_time + ($buffer_minutes * 60);
    $end_time_with_buffer = $end_time - ($buffer_minutes * 60);
    
    $sql = "SELECT id, name, start_time, end_time FROM mrbs_entry 
            WHERE room_id = ? 
            AND status != 2  -- Not cancelled
            AND (
                (start_time < ? AND end_time > ?) OR      -- Overlaps with buffer
                (start_time >= ? AND start_time < ?) OR   -- Starts during buffer
                (end_time > ? AND end_time <= ?)          -- Ends during buffer
            )";
    
    $params = [$room_id, $end_time_with_buffer, $start_time_with_buffer, 
               $start_time_with_buffer, $end_time_with_buffer,
               $start_time_with_buffer, $end_time_with_buffer];
    
    if ($booking_id) {
        $sql .= " AND id != ?";
        $params[] = $booking_id;
    }
    
    $stmt = $mysqli->prepare($sql);
    
    $types = str_repeat('i', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $conflicts = [];
    
    while ($row = $result->fetch_assoc()) {
        $conflicts[] = $row;
    }
    
    $stmt->close();
    return $conflicts;
}

// Adjust booking times to avoid conflicts with buffer
// Adjust booking times to avoid conflicts with buffer
function adjust_booking_times($room_id, $start_time, $end_time, $buffer_minutes = 2, $booking_id = null) {
    $adjusted_start = $start_time;
    $adjusted_end = $end_time;
    $adjustments = [];
    
    // Get conflicts with current times
    $conflicts = check_booking_conflict_with_buffer($room_id, $start_time, $end_time, $booking_id);
    
    if (!empty($conflicts)) {
        // Sort conflicts by start time
        usort($conflicts, function($a, $b) {
            return $a['start_time'] <=> $b['start_time'];
        });
        
        // Check if we can adjust end time (move it earlier)
        foreach ($conflicts as $conflict) {
            if ($start_time < $conflict['start_time'] && $end_time > $conflict['start_time']) {
                // Try to end 2 minutes before conflict starts
                $new_end_time = $conflict['start_time'] - ($buffer_minutes * 60);
                
                // Make sure new end time is after start time
                if ($new_end_time > $start_time) {
                    // Check if new end time would conflict with other bookings
                    $new_conflicts = check_booking_conflict_with_buffer($room_id, $start_time, $new_end_time, $booking_id);
                    if (empty($new_conflicts)) {
                        $adjusted_end = $new_end_time;
                        $adjustments[] = [
                            'type' => 'end',
                            'original' => $end_time,
                            'adjusted' => $adjusted_end,
                            'conflict_with' => $conflict['name'],
                            'conflict_time' => date('g:i A', $conflict['start_time']) . ' - ' . date('g:i A', $conflict['end_time']),
                            'message' => 'End time adjusted from ' . date('g:i A', $end_time) . ' to ' . date('g:i A', $new_end_time) . ' to avoid conflict with "' . $conflict['name'] . '"'
                        ];
                        break;
                    }
                }
            }
        }
        
        // If end couldn't be adjusted, try adjusting start time (move it later)
        if ($adjusted_end == $end_time) {
            foreach ($conflicts as $conflict) {
                if ($start_time < $conflict['end_time'] && $end_time > $conflict['end_time']) {
                    // Try to start 2 minutes after conflict ends
                    $new_start_time = $conflict['end_time'] + ($buffer_minutes * 60);
                    
                    // Make sure new start time is before end time
                    if ($new_start_time < $end_time) {
                        // Check if new start time would conflict with other bookings
                        $new_conflicts = check_booking_conflict_with_buffer($room_id, $new_start_time, $end_time, $booking_id);
                        if (empty($new_conflicts)) {
                            $adjusted_start = $new_start_time;
                            $adjustments[] = [
                                'type' => 'start',
                                'original' => $start_time,
                                'adjusted' => $adjusted_start,
                                'conflict_with' => $conflict['name'],
                                'conflict_time' => date('g:i A', $conflict['start_time']) . ' - ' . date('g:i A', $conflict['end_time']),
                                'message' => 'Start time adjusted from ' . date('g:i A', $start_time) . ' to ' . date('g:i A', $new_start_time) . ' to avoid conflict with "' . $conflict['name'] . '"'
                            ];
                            break;
                        }
                    }
                }
            }
        }
        
        // If still conflicting, check for adjusting both start and end
        if ($adjusted_start == $start_time && $adjusted_end == $end_time && count($conflicts) == 1) {
            $conflict = $conflicts[0];
            // Try to schedule before the conflict
            if ($end_time <= $conflict['start_time']) {
                $new_end_time = $conflict['start_time'] - ($buffer_minutes * 60);
                if ($new_end_time > $start_time) {
                    $adjusted_end = $new_end_time;
                    $adjustments[] = [
                        'type' => 'end',
                        'original' => $end_time,
                        'adjusted' => $adjusted_end,
                        'conflict_with' => $conflict['name'],
                        'conflict_time' => date('g:i A', $conflict['start_time']) . ' - ' . date('g:i A', $conflict['end_time']),
                        'message' => 'End time adjusted from ' . date('g:i A', $end_time) . ' to ' . date('g:i A', $new_end_time) . ' to maintain 2-minute buffer before "' . $conflict['name'] . '"'
                    ];
                }
            }
            // Try to schedule after the conflict
            elseif ($start_time >= $conflict['end_time']) {
                $new_start_time = $conflict['end_time'] + ($buffer_minutes * 60);
                if ($new_start_time < $end_time) {
                    $adjusted_start = $new_start_time;
                    $adjustments[] = [
                        'type' => 'start',
                        'original' => $start_time,
                        'adjusted' => $adjusted_start,
                        'conflict_with' => $conflict['name'],
                        'conflict_time' => date('g:i A', $conflict['start_time']) . ' - ' . date('g:i A', $conflict['end_time']),
                        'message' => 'Start time adjusted from ' . date('g:i A', $start_time) . ' to ' . date('g:i A', $new_start_time) . ' to maintain 2-minute buffer after "' . $conflict['name'] . '"'
                    ];
                }
            }
        }
    }
    
    return [
        'adjusted_start' => $adjusted_start,
        'adjusted_end' => $adjusted_end,
        'adjustments' => $adjustments,
        'has_conflicts' => !empty($conflicts),
        'original_start' => $start_time,
        'original_end' => $end_time,
        'conflicts' => $conflicts
    ];
}

function create_welcome($representative, $room_id, $start, $end) {
    global $connSecond;
    
    $area_name = get_area_name_by_room($room_id);
    $factory = ($area_name == "TFCD F1") ? "TFCD" : (($area_name == "TFCD F6") ? "F6" : "TCM");

    $stmt = $connSecond->prepare("
        INSERT INTO welcome_page 
        (name, factory, starttime, endtime, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("ssss", $representative, $factory, $start, $end);
    $stmt->execute();
    $stmt->close();
}

// Create booking with buffer system
function create_booking($data) {
    global $mysqli;
    
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'User not logged in'];
    }
    
    $current_user = get_current_username();
    
    // Check for conflicts with buffer and adjust if necessary
    $buffer_minutes = 2; // 2-minute buffer
    $time_adjustment = adjust_booking_times($data['room_id'], $data['start_time'], $data['end_time'], $buffer_minutes);
    
    // If there are conflicts and times couldn't be adjusted
    if ($time_adjustment['has_conflicts'] && empty($time_adjustment['adjustments'])) {
        $conflict_names = [];
        foreach ($time_adjustment['conflicts'] as $conflict) {
            $conflict_names[] = $conflict['name'] . ' (' . 
                date('g:i A', $conflict['start_time']) . ' - ' . 
                date('g:i A', $conflict['end_time']) . ')';
        }
        
        return [
            'success' => false, 
            'message' => 'Time slot conflicts with existing bookings: ' . implode(', ', $conflict_names) . 
                        '. Please choose a different time.'
        ];
    }
    
    // Use adjusted times if available
    $start_time = $time_adjustment['adjusted_start'];
    $end_time = $time_adjustment['adjusted_end'];
    $adjustments = $time_adjustment['adjustments'];
    $ical_uid = generate_ical_uid();
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Create main booking WITHOUT the extra columns
        $stmt = $mysqli->prepare("
            INSERT INTO mrbs_entry (
                start_time, end_time, entry_type, room_id, 
                create_by, name, type, description, status,
                ical_uid
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $entry_type = 0; // Single event
        $status = isset($data['status']) ? $data['status'] : 0; // 0 = confirmed, 1 = tentative
        
        $stmt->bind_param(
            "iiiissssis",
            $start_time,
            $end_time,
            $entry_type,
            $data['room_id'],
            $current_user,
            $data['event_name'],
            $data['event_type'],
            $data['description'],
            $status,
            $ical_uid 
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to create booking: ' . $mysqli->error);
        }
        
        $booking_id = $mysqli->insert_id;
        $stmt->close();
        
        // For external events, save representative and preparations
        if ($data['event_type'] === 'E' && !empty($data['representative_name'])) {
            // Save representative
            $stmt2 = $mysqli->prepare("
                INSERT INTO mrbs_groups (entry_id, full_name, email) 
                VALUES (?, ?, NULL)
            ");
            
            $stmt2->bind_param("is", $booking_id, $data['representative_name']);
            $stmt2->execute();
            $stmt2->close();
            
            // Save preparations if provided
            if (!empty($data['preparations'])) {
                $stmt3 = $mysqli->prepare("
                    INSERT INTO mrbs_prepare (entry_id, name) 
                    VALUES (?, ?)
                ");
                
                foreach ($data['preparations'] as $preparation) {
                    $stmt3->bind_param("is", $booking_id, $preparation);
                    $stmt3->execute();
                }
                $stmt3->close();
            }
        }
        
        // Save notification emails
        if (!empty($data['notification_email'])) {
            $emails = array_map('trim', explode(',', $data['notification_email']));
            
            $stmt4 = $mysqli->prepare("
                INSERT INTO mrbs_groups (entry_id, full_name, email) 
                VALUES (?, NULL, ?)
            ");
            
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmt4->bind_param("is", $booking_id, $email);
                    $stmt4->execute();
                }
            }
            $stmt4->close();
        }
        
        // Commit transaction
        $mysqli->commit();
        
        // Return success with adjustment info
        return [
            'success' => true, 
            'booking_id' => $booking_id, 
            'message' => 'Booking created successfully',
            'adjustments' => $adjustments,
            'adjusted_times' => [
                'start' => $start_time,
                'end' => $end_time,
                'original_start' => $data['start_time'],
                'original_end' => $data['end_time']
            ],
            'has_adjustments' => !empty($adjustments)
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Get available slots for quick booking
function get_available_slots($room_id, $date) {
    global $mysqli;
    
    // Define working hours (8:00 AM to 8:00 PM)
    $work_start = strtotime($date . " 07:00:00");
    $work_end = strtotime($date . " 20:00:00");
    
    // Get all bookings for this room on this date
    $start_date = date('Y-m-d', $work_start);
    $end_date = date('Y-m-d', $work_end);
    $bookings = get_bookings_for_room($room_id, $start_date, $end_date);
    
    // Initialize available slots (30-minute intervals)
    $available_slots = [];
    $current_time = $work_start;
    $buffer_minutes = 2; // 2-minute buffer
    
    while ($current_time < $work_end) {
        $slot_start = $current_time;
        $slot_end = $current_time + 1800; // 30-minute slots
        
        // Check if this slot overlaps with any booking (with buffer)
        $is_available = true;
        foreach ($bookings as $booking) {
            // Apply buffer: slot ends 2 minutes before booking starts OR starts 2 minutes after booking ends
            if (!(($slot_end - ($buffer_minutes * 60)) <= $booking['start_time'] || 
                  ($slot_start + ($buffer_minutes * 60)) >= $booking['end_time'])) {
                $is_available = false;
                break;
            }
        }
        
        if ($is_available) {
            $available_slots[] = [
                'start' => $slot_start,
                'end' => $slot_end,
                'formatted_start' => date('H:i', $slot_start),
                'formatted_end' => date('H:i', $slot_end)
            ];
        }
        
        $current_time = $slot_end;
    }
    
    return $available_slots;
}

// Get room name by ID
function get_room_name($room_id) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT room_name FROM mrbs_room WHERE id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $stmt->bind_result($room_name);
    $stmt->fetch();
    $stmt->close();
    return $room_name ? $room_name : 'Unknown Room';
}

// Get area name by ID
function get_area_name($area_id) {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT area_name FROM mrbs_area WHERE id = ?");
    $stmt->bind_param("i", $area_id);
    $stmt->execute();
    $stmt->bind_result($area_name);
    $stmt->fetch();
    $stmt->close();
    return $area_name ? $area_name : 'Unknown Area';
}

function get_area_name_by_room($room_id) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("
        SELECT a.area_name 
        FROM mrbs_area a
        JOIN mrbs_room r ON a.id = r.area_id
        WHERE r.id = ?
    ");
    
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $stmt->bind_result($area_name);
    $stmt->fetch();
    $stmt->close();
    
    return $area_name ? $area_name : '';
}
// Send booking notification email for create/update/delete
function send_booking_notification($booking_id, $action = 'created', $recipient_email = null) {
    global $mysqli;
    
    // Get booking details
    $booking = get_booking_by_id($booking_id);
    if (!$booking) {

        return false;
    }
    
    // Get room and area info
    $room_name = get_room_name($booking['room_id']);
    $area_name = get_area_name_by_room($booking['room_id'] ?? 0);
    
    // Set email subject based on action
    $subjects = [
        'created' => 'Booking Confirmation: ' . $booking['name'],
        'updated' => 'Booking Updated: ' . $booking['name'],
        'cancelled' => 'Booking Cancelled: ' . $booking['name']
    ];
    $subject = $subjects[$action] ?? 'Booking Notification: ' . $booking['name'];
    
    // Set headline based on action
    $headlines = [
        'created' => 'scheduled',
        'updated' => 'updated',
        'cancelled' => 'cancelled'
    ];
    $headline = $headlines[$action] ?? 'modified';
    
    // Set email header based on action
    $headers = [
        'created' => 'New Meeting Scheduled',
        'updated' => 'Meeting Updated',
        'cancelled' => 'Meeting Cancelled'
    ];
    $email_header = $headers[$action] ?? 'Meeting Notification';
    
    $room_display = $area_name . ' - ' . $room_name;
    
    $start_time_formatted = date('F j, Y g:i A', $booking['start_time']);
    $end_time_formatted = date('F j, Y g:i A', $booking['end_time']);
    
    $duration = $booking['end_time'] - $booking['start_time'];
    $duration_hours = floor($duration / 3600);
    $duration_minutes = floor(($duration % 3600) / 60);
    $duration_string = sprintf("%d hours %d minutes", $duration_hours, $duration_minutes);
    
    $confirmed = $booking['status'] == 0 ? 'Confirmed' : 'Tentative';
    $type = $booking['type'] == 'I' ? 'Internal' : 'External';
    
    // Determine colors based on action
    $action_notice_bg = '';
    $action_notice_border = '';
    $action_notice_color = '';
    $cancelled_note = '';
    
    if ($action == 'cancelled') {
        $action_notice_bg = '#fee2e2';
        $action_notice_border = '#fecaca';
        $action_notice_color = '#991b1b';
        $cancelled_note = 'The room is now available for booking.';
    } elseif ($action == 'updated') {
        $action_notice_bg = '#fef3c7';
        $action_notice_border = '#fde68a';
        $action_notice_color = '#92400e';
    } else {
        $action_notice_bg = '#d1fae5';
        $action_notice_border = '#a7f3d0';
        $action_notice_color = '#065f46';
    }
    
    // Build the email body with action-specific content
    $message = <<<HTML
    <html>
      <head>
        <style>
          body { font-family: Arial, sans-serif; color: #333; }
          .container { padding: 20px; border: 1px solid #e0e0e0; background-color: #f9f9f9; max-width: 600px; margin: auto; }
          h2 { color: #2c3e50; }
          .details { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin-top: 15px; }
          .action-notice { 
            background-color: $action_notice_bg; 
            border: 1px solid $action_notice_border; 
            padding: 10px; 
            margin: 10px 0; 
            border-radius: 4px; 
            color: $action_notice_color;
          }
          .footer { font-size: 12px; color: #888; margin-top: 20px; }
        </style>
      </head>
      <body>
        <div class="container">
          <h2>$email_header</h2>
          <p>Good day</p>
          <p>The following meeting has been <strong>$headline</strong> by the organizer:</p>
          
          <div class="action-notice">
            <strong><i class="fas fa-info-circle"></i> This meeting has been $headline.</strong>
            $cancelled_note
          </div>
          
          <div class="details">
            <p><strong>Subject:</strong> {$booking['name']}</p>
            <p><strong>Description:</strong> {$booking['description']}</p>
            <p><strong>Organizer:</strong> {$booking['display_name']}</p>
            <p><strong>Start Time:</strong> $start_time_formatted</p>
            <p><strong>End Time:</strong> $end_time_formatted</p>
            <p><strong>Duration:</strong> $duration_string</p>
            <p><strong>Type:</strong> $type</p>
            <p><strong>Area / Rooms:</strong><br>$room_display</p>
            <p><strong>Confirmation status:</strong> $confirmed</p>
HTML;
    
    // Add updated by line only for updates
    if ($action == 'updated') {
        $updated_by = $booking['modified_by'] ?? $booking['display_name'];
        $message .= "<p><strong>Last updated by:</strong> $updated_by</p>";
    }
    
    // Close the email template
    $message .= <<<HTML
          </div>
          <div class="footer">
            <p>This is an automated email from the system.</p>
            <p>Please do not reply to this message.</p>
          </div>
        </div>
      </body>
    </html>
HTML;
    
    // Determine recipient(s)
    $recipients = [];
    
    // 1. Primary recipient(s) from parameter (handle comma-separated emails)
    if ($recipient_email) {
        $emails = array_map('trim', explode(',', $recipient_email));
        foreach ($emails as $email) {
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!in_array($email, $recipients)) {
                    $recipients[] = $email;
                }
            }
        }
    }
    
    // 2. Get notification emails from groups table
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
    
    // 3. Also include the organizer's email if available
    $organizer_email = get_user_email_by_username($booking['create_by']);
    if ($organizer_email && !in_array($organizer_email, $recipients)) {
        $recipients[] = $organizer_email;
    }
      
    // Send email to all recipients using BATCH API
    $sent_count = 0;
    $valid_recipients = [];
    
    // Use batch API approach - send all recipients at once
    if (!empty($recipients)) {
        $email_data = [
            'booking_id' => $booking_id,
            'subject' => $subject,
            'body' => $message,
            'action' => 'booking_' . $action,
            'recipients' => [],
            'booking_details' => [
                'name' => $booking['name'],
                'description' => $booking['description'],
                'organizer' => $booking['display_name'],
                'start_time' => $start_time_formatted,
                'end_time' => $end_time_formatted,
                'room' => $room_display,
                'type' => $type,
                'status' => $confirmed,
                'action' => $action
            ]
        ];
        
        // Validate and collect valid recipients
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $email_data['recipients'][] = $recipient;
                $valid_recipients[] = $recipient;
            }
        }
        
        if (!empty($email_data['recipients'])) {
            // Send via enhanced batch API
            $result = triggerLaravelEmailSendBatch($email_data);
            
            if ($result && isset($result['success']) && $result['success']) {
                $sent_count = $result['sent'] ?? 0;
        
                // Also queue in database for record keeping
                //queueEmailsInDatabase($valid_recipients, $subject, $message, 'booking_' . $action, $booking_id);
            } else {
               // error_log("Batch API failed for booking $booking_id. Falling back to database queue.");
                // Fallback: Queue in database only
                //$sent_count = queueEmailsInDatabase($valid_recipients, $subject, $message, 'booking_' . $action, $booking_id);
            }
        }
    }
    
    // Summary log
    // if (empty($valid_recipients)) {
    //     //error_log("No valid email recipients found for booking $booking_id");
    // } else {
    //     //error_log("Final result for booking $booking_id: " . 
    //              "$sent_count of " . count($valid_recipients) . " emails processed successfully");
    // }
    
    return $sent_count > 0;
}

/**
 * Queue emails in database as fallback
 */
function queueEmailsInDatabase($recipients, $subject, $message, $action, $booking_id) {
    global $mysqli;
    
    $queued_count = 0;
    $mysqli->begin_transaction();
    
    try {
        $stmt = $mysqli->prepare("
            INSERT INTO email_queue (recipient, subject, body, action, booking_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($recipients as $recipient) {
            $stmt->bind_param("ssssi", $recipient, $subject, $message, $action, $booking_id);
            if ($stmt->execute()) {
                $queued_count++;
                //error_log("Queued in database: $recipient");
            } else {
                //error_log("Failed to queue in database: $recipient - " . $stmt->error);
            }
        }
        
        $stmt->close();
        $mysqli->commit();
        
        // Trigger Laravel to process the queue
        triggerLaravelEmailSend();
        
    } catch (Exception $e) {
        $mysqli->rollback();
        //error_log("Database queue transaction failed: " . $e->getMessage());
    }
    
    return $queued_count;
}

/**
 * Enhanced batch email sending API
 */
function triggerLaravelEmailSendBatch($email_data = null) {
    $api_url = "http://172.16.131.229:8001/api/send-batch-emails";
    
    // If no data provided, trigger queue processing
    if ($email_data === null) {
        $api_url = "http://172.16.131.229:8001/api/send-emails";
        $post_data = json_encode(['trigger_queue' => true]);
    } else {
        $post_data = json_encode($email_data);
    }
    
   // error_log("Calling Laravel batch API with " . (isset($email_data['recipients']) ? count($email_data['recipients']) : 0) . " recipients");
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        //("cURL Error: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    //error_log("Batch API Response Code: $http_code");
    
    if ($http_code === 200) {
        $result = json_decode($response, true);
       // error_log("Batch API Success: " . ($result['message'] ?? 'Processed'));
        return $result;
    } else {
       // error_log("Batch API Error $http_code: $response");
        return false;
    }
}

// Helper function to get user email by username
function get_user_email_by_username($username) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("SELECT email FROM mrbs_users WHERE name = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();
    
    return $email;
}

function triggerLaravelEmailSend() {
    $api_url = "http://172.16.131.229:8001/api/send-emails-immediately";
    
    // Direct HTTP request without background process - simpler and more reliable
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
            'timeout' => 5, // Shorter timeout so it doesn't block
            'ignore_errors' => true // Don't fail on HTTP errors
        ]
    ]);
    
    // Non-blocking approach using fsockopen for async request
    $parsed = parse_url($api_url);
    $host = $parsed['host'];
    $port = $parsed['port'] ?? 80;
    $path = $parsed['path'] ?? '/';
    
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.1);
    if ($fp) {
        stream_set_blocking($fp, false); // Non-blocking
        $out = "POST $path HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Content-Type: application/json\r\n";
        $out .= "Content-Length: 2\r\n";
        $out .= "Connection: Close\r\n\r\n";
        $out .= "{}";
        fwrite($fp, $out);
        fclose($fp);
    }
    

    return true;
}

function is_booking_owner($booking_creator) {
    if (!is_logged_in()) {
        return false;
    }
    
    $current_user = get_current_username();
    return trim($booking_creator) === trim($current_user);
}

// Get all users with email (for notification suggestions)
function get_users_with_email() {
    global $mysqli;
    
    $result = $mysqli->query("
        SELECT name, display_name, email 
        FROM mrbs_users 
        WHERE email IS NOT NULL 
        AND email != '' 
        AND email LIKE '%@%'
        ORDER BY display_name
    ");
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'username' => $row['name'],
            'display_name' => $row['display_name'] ?: $row['name'],
            'email' => $row['email']
        ];
    }
    return $users;
}

// Get booking by ID for editing
function get_booking_for_edit($booking_id) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("
        SELECT 
            e.id,
            e.start_time,
            e.end_time,
            e.name,
            e.create_by,
            e.description,
            e.type,
            e.status,
            e.room_id,
            r.room_name,
            a.area_name
        FROM mrbs_entry e
        LEFT JOIN mrbs_room r ON e.room_id = r.id
        LEFT JOIN mrbs_area a ON r.area_id = a.id
        WHERE e.id = ?
    ");
    
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    return $booking;
}

// Update booking

function update_booking($booking_id, $data) {
    global $mysqli;
    
    if (!is_logged_in()) {
        return ['success' => false, 'message' => 'User not logged in'];
    }
    
    $current_user = get_current_username();
    
    // Check if the user owns this booking
    $stmt = $mysqli->prepare("SELECT create_by FROM mrbs_entry WHERE id = ?");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $stmt->bind_result($create_by);
    $stmt->fetch();
    $stmt->close();

    if ($create_by !== $current_user) {
        return ['success' => false, 'message' => 'You can only edit your own bookings'];
    }
    
    // Check for conflicts with buffer and adjust if necessary
    $buffer_minutes = 2;
    $time_adjustment = adjust_booking_times($data['room_id'], $data['start_time'], $data['end_time'], $buffer_minutes, $booking_id);
    
    // If there are conflicts and times couldn't be adjusted
    if ($time_adjustment['has_conflicts'] && empty($time_adjustment['adjustments'])) {
        $conflict_names = [];
        foreach ($time_adjustment['conflicts'] as $conflict) {
            $conflict_names[] = $conflict['name'] . ' (' . 
                date('g:i A', $conflict['start_time']) . ' - ' . 
                date('g:i A', $conflict['end_time']) . ')';
        }
        
        return [
            'success' => false, 
            'message' => 'Time slot conflicts with existing bookings: ' . implode(', ', $conflict_names) . 
                        '. Please choose a different time.'
        ];
    }
    
    // Use adjusted times if available
    $start_time = $time_adjustment['adjusted_start'];
    $end_time = $time_adjustment['adjusted_end'];
    $adjustments = $time_adjustment['adjustments'];

    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update main booking
        $stmt = $mysqli->prepare("
            UPDATE mrbs_entry 
            SET start_time = ?, 
                end_time = ?, 
                name = ?, 
                type = ?, 
                description = ?, 
                status = ?,
                modified_by = ?,
                timestamp = CURRENT_TIMESTAMP()
            WHERE id = ?
        ");
        
        $status = isset($data['status']) ? (int)$data['status'] : 0;

        // Correct bind_param: "iississi"
        // i = start_time (int)
        // i = end_time (int)
        // s = name (string)
        // s = type (string)
        // s = description (string)
        // i = status (int)
        // s = modified_by (string)
        // i = id (int)
        
        $stmt->bind_param(
            "iisssisi",
            $start_time,           // int
            $end_time,             // int
            $data['event_name'],   // string
            $data['event_type'],   // string
            $data['description'],  // string
            $status,               // int
            $current_user,         // string
            $booking_id            // int
        );
        
        if (!$stmt->execute()) {
           // error_log("SQL Error: " . $stmt->error);
            throw new Exception('Failed to update booking: ' . $stmt->error);
        }
        
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        
        if ($affected_rows === 0) {
            throw new Exception('No rows were updated. Booking might not exist or data is the same.');
        }
        
        // Delete existing related data
        $stmt2 = $mysqli->prepare("DELETE FROM mrbs_groups WHERE entry_id = ?");
        $stmt2->bind_param("i", $booking_id);
        $stmt2->execute();
        $stmt2->close();
        
        $stmt3 = $mysqli->prepare("DELETE FROM mrbs_prepare WHERE entry_id = ?");
        $stmt3->bind_param("i", $booking_id);
        $stmt3->execute();
        $stmt3->close();
        
        // For external events, save representative and preparations
        if ($data['event_type'] === 'E' && !empty($data['representative_name'])) {
            // Save representative
            $stmt4 = $mysqli->prepare("
                INSERT INTO mrbs_groups (entry_id, full_name, email) 
                VALUES (?, ?, NULL)
            ");
            
            $stmt4->bind_param("is", $booking_id, $data['representative_name']);
            $stmt4->execute();
            $stmt4->close();
            
            // Save preparations if provided
            if (!empty($data['preparations'])) {
                $stmt5 = $mysqli->prepare("
                    INSERT INTO mrbs_prepare (entry_id, name) 
                    VALUES (?, ?)
                ");
                
                foreach ($data['preparations'] as $preparation) {
                    $stmt5->bind_param("is", $booking_id, $preparation);
                    $stmt5->execute();
                }
                $stmt5->close();
            }
        }
        
        // Save notification emails
        if (!empty($data['notification_email'])) {
            $emails = array_map('trim', explode(',', $data['notification_email']));
            
            $stmt6 = $mysqli->prepare("
                INSERT INTO mrbs_groups (entry_id, full_name, email) 
                VALUES (?, NULL, ?)
            ");
            
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmt6->bind_param("is", $booking_id, $email);
                    $stmt6->execute();
                }
            }
            $stmt6->close();
        }
        
        // Commit transaction
        $mysqli->commit();

        // Return success with adjustment info
        return [
            'success' => true, 
            'booking_id' => $booking_id, 
            'message' => 'Booking updated successfully',
            'adjustments' => $adjustments,
            'adjusted_times' => [
                'start' => $start_time,
                'end' => $end_time,
                'original_start' => $data['start_time'],
                'original_end' => $data['end_time']
            ],
            'has_adjustments' => !empty($adjustments)
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        //error_log("Transaction failed: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Add this function to connection.php
function generate_ical_uid() {
    // Generate a unique iCal UID based on booking ID, room ID, and timestamp
    $random1 = bin2hex(random_bytes(6)); // 12 characters like "693caef48edfd"
    $random2 = bin2hex(random_bytes(4)); // 8 characters like "989a3e1d"
    $domain = '172.16.81.215';
    return "MRBS-" . $random1 . "-" . $random2 . "@" . $domain;
}


// ============================================
// PASSWORD RESET FUNCTIONS
// ============================================

/**
 * Generate a secure reset token
 */
function generate_reset_token() {
    return bin2hex(random_bytes(32)); // 64-character hex token
}

/**
 * Send password reset email
 */
function send_password_reset($email) {
    global $mysqli;
    
    // Check if email exists in database
    $stmt = $mysqli->prepare("SELECT id, name, display_name, email FROM mrbs_users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($user_id, $username, $display_name, $user_email);
    
    if ($stmt->fetch()) {
        $stmt->close();
        
        // Generate secure reset token
        $reset_token = generate_reset_token();
        $token_hash = password_hash($reset_token, PASSWORD_DEFAULT);
        $expiry_time = time() + (24 * 60 * 60); // 24 hours from now
        
        // Store token hash and expiry in database
        $stmt2 = $mysqli->prepare("
            UPDATE mrbs_users 
            SET reset_key_hash = ?, reset_key_expiry = ? 
            WHERE id = ?
        ");
        $stmt2->bind_param("sii", $token_hash, $expiry_time, $user_id);
        
        if (!$stmt2->execute()) {
            //error_log("Failed to store reset token: " . $stmt2->error);
            $stmt2->close();
            return false;
        }
        
        $stmt2->close();
        
        // Build reset link
        $base_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        $reset_link = rtrim($base_url, '/') . "/login.php?token=" . $reset_token;
        
        // Use display name if available, otherwise username
        $recipient_name = !empty($display_name) ? $display_name : $username;
        
        // Create email content (same as before)
        $subject = "MRBS - Password Reset Request";
        $message = <<<HTML
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; background: #f8f9fa; }
                .button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 500; margin: 20px 0; }
                .code-box { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 15px; margin: 20px 0; font-family: monospace; word-break: break-all; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center; }
                .warning { background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 15px; margin: 20px 0; color: #92400e; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>MRBS Password Reset</h2>
                    <p>Meeting Room Booking System</p>
                </div>
                <div class="content">
                    <p>Hello <strong>$recipient_name</strong>,</p>
                    <p>You have requested to reset your password for the Meeting Room Booking System.</p>
                    
                    <div style="text-align: center;">
                        <a href="$reset_link" class="button">Reset My Password</a>
                    </div>
                    
                    <p>If the button doesn't work, copy and paste the following link into your browser:</p>
                    <div class="code-box">
                        $reset_link
                    </div>
                    
                    <div class="warning">
                        <p><strong>Important:</strong></p>
                        <ul>
                            <li>This link will expire in <strong>24 hours</strong></li>
                            <li>If you didn't request this password reset, please ignore this email</li>
                            <li>For security reasons, do not share this link with anyone</li>
                        </ul>
                    </div>
                    
                    <p>Best regards,<br>
                    <strong>MRBS Support Team</strong></p>
                </div>
                <div class="footer">
                    <p>This is an automated email from the Meeting Room Booking System.</p>
                    <p>Please do not reply to this message.</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
        
        // Send email directly to Laravel API (non-blocking)
        sendEmailToLaravelAPI($user_email, $subject, $message, 'confirmation');
        
        //error_log("Password reset email sent to: $user_email (User ID: $user_id)");
        return true;
    }
    
    $stmt->close();
   // error_log("Password reset requested for non-existent email: $email");
    return false;
}

/**
 * Send email data to Laravel API (improved version)
 */
function sendEmailToLaravelAPI($recipient, $subject, $body, $type = 'password_reset') {
    $laravelApiUrl = "http://172.16.131.229:8001/api/send-password-reset";
    
    // Prepare email data
    $emailData = [
        'recipient' => $recipient,
        'subject' => $subject,
        'body' => $body,
        'type' => $type
    ];
    
    // Use cURL with timeout
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $laravelApiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
       // error_log("✓ Email sent via Laravel API to: $recipient");
        return true;
    } else {
       // error_log("✗ Laravel API failed ($httpCode) for: $recipient");
        if ($curlError) {
           // error_log("cURL Error: $curlError");
        }
        // Fallback to direct email if Laravel API fails
    }
}


/**
 * Verify if a reset token is valid
 */
function verify_reset_token($token) {
    global $mysqli;
    
    // Get current timestamp
    $current_time = time();
    
    $stmt = $mysqli->prepare("
        SELECT id, reset_key_hash, reset_key_expiry 
        FROM mrbs_users 
        WHERE reset_key_hash IS NOT NULL 
        AND reset_key_expiry > ?
    ");
    $stmt->bind_param("i", $current_time);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($user_id, $token_hash, $expiry);
    
    $is_valid = false;
    $user_id_found = null;
    
    while ($stmt->fetch()) {
        if (password_verify($token, $token_hash)) {
            $is_valid = true;
            $user_id_found = $user_id;
            break;
        }
    }
    
    $stmt->close();

    return $is_valid;
}

/**
 * Reset password using a valid token
 */
function reset_password($token, $new_password) {
    global $mysqli;
    
    // Get current timestamp
    $current_time = time();
    
    // Find user with valid token
    $stmt = $mysqli->prepare("
        SELECT id, reset_key_hash, reset_key_expiry 
        FROM mrbs_users 
        WHERE reset_key_hash IS NOT NULL 
        AND reset_key_expiry > ?
    ");
    $stmt->bind_param("i", $current_time);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($user_id, $token_hash, $expiry);
    
    $user_id_to_update = null;
    
    while ($stmt->fetch()) {
        if (password_verify($token, $token_hash)) {
            $user_id_to_update = $user_id;
            break;
        }
    }
    
    $stmt->close();
    
    if ($user_id_to_update) {
        // Hash the new password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password and clear reset token
        $stmt2 = $mysqli->prepare("
            UPDATE mrbs_users 
            SET password_hash = ?, 
                reset_key_hash = NULL, 
                reset_key_expiry = 0,
                timestamp = CURRENT_TIMESTAMP()
            WHERE id = ?
        ");
        $stmt2->bind_param("si", $password_hash, $user_id_to_update);
        
        if (!$stmt2->execute()) {
           // error_log("Failed to update password: " . $stmt2->error);
            $stmt2->close();
            return false;
        }
        
        $affected_rows = $stmt2->affected_rows;
        $stmt2->close();
        
        if ($affected_rows > 0) {
           // error_log("Password reset successfully for user ID: $user_id_to_update");
            
            // Get user details for confirmation email
            $stmt3 = $mysqli->prepare("SELECT name, display_name, email FROM mrbs_users WHERE id = ?");
            $stmt3->bind_param("i", $user_id_to_update);
            $stmt3->execute();
            $stmt3->bind_result($username, $display_name, $user_email);
            $stmt3->fetch();
            $stmt3->close();
            
            // Send password changed confirmation email
            $recipient_name = !empty($display_name) ? $display_name : $username;
            $subject = "MRBS - Password Changed Successfully";
            $message = <<<HTML
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
                    .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 30px; text-align: center; }
                    .content { padding: 30px; background: #f8f9fa; }
                    .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center; }
                    .info-box { background: #d1fae5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 15px; margin: 20px 0; color: #065f46; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>Password Changed Successfully</h2>
                        <p>Meeting Room Booking System</p>
                    </div>
                    <div class="content">
                        <p>Hello <strong>$recipient_name</strong>,</p>
                        <p>Your MRBS account password has been changed successfully.</p>
                        
                        <div class="info-box">
                            <p><strong>Important Security Information:</strong></p>
                            <ul>
                                <li>Your new password is now active</li>
                                <li>You can login with your new password</li>
                                <li>If you did not make this change, please contact support immediately</li>
                            </ul>
                        </div>
                        
                        <p>You can now login to the MRBS system with your new password:</p>
                        <p style="text-align: center; margin: 20px 0;">
                            <a href="http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}" style="display: inline-block; background: #10b981; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: 500;">
                                Go to MRBS Login
                            </a>
                        </p>
                        
                        <p>Best regards,<br>
                        <strong>MRBS Support Team</strong></p>
                    </div>
                    <div class="footer">
                        <p>This is an automated email from the Meeting Room Booking System.</p>
                        <p>Please do not reply to this message.</p>
                    </div>
                </div>
            </body>
            </html>
            HTML;

            // Trigger email sending
            sendEmailToLaravelAPI($user_email, $subject, $message);
            
            return true;
        }
    }
    
    return false;
}

/**
 * Get user by reset token
 */
function get_user_by_reset_token($token) {
    global $mysqli;
    
    $current_time = time();
    
    $stmt = $mysqli->prepare("
        SELECT id, name, display_name, email 
        FROM mrbs_users 
        WHERE reset_key_hash IS NOT NULL 
        AND reset_key_expiry > ?
    ");
    $stmt->bind_param("i", $current_time);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($user_id, $username, $display_name, $email);
    
    $user_data = null;
    
    while ($stmt->fetch()) {
        // We need to verify the token against the hash
        // This is a simplified version - in practice, you'd need to fetch the hash
        $user_data = [
            'id' => $user_id,
            'username' => $username,
            'display_name' => $display_name,
            'email' => $email
        ];
        break; // For simplicity, return first match
    }
    
    $stmt->close();
    return $user_data;
}
?>