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
            e.room_id
        FROM mrbs_entry e
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
        SELECT e.*, r.room_name, a.area_name 
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
function adjust_booking_times($room_id, $start_time, $end_time, $buffer_minutes = 2) {
    $adjusted_start = $start_time;
    $adjusted_end = $end_time;
    $adjustments = [];
    
    // Get conflicts with current times
    $conflicts = check_booking_conflict_with_buffer($room_id, $start_time, $end_time);
    
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
                    $new_conflicts = check_booking_conflict_with_buffer($room_id, $start_time, $new_end_time);
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
                        $new_conflicts = check_booking_conflict_with_buffer($room_id, $new_start_time, $end_time);
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
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Create main booking WITHOUT the extra columns
        $stmt = $mysqli->prepare("
            INSERT INTO mrbs_entry (
                start_time, end_time, entry_type, room_id, 
                create_by, name, type, description, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $entry_type = 0; // Single event
        $status = isset($data['status']) ? $data['status'] : 0; // 0 = confirmed, 1 = tentative
        
        $stmt->bind_param(
            "iiiissssi",
            $start_time,
            $end_time,
            $entry_type,
            $data['room_id'],
            $current_user,
            $data['event_name'],
            $data['event_type'],
            $data['description'],
            $status
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

// Send booking confirmation email
function send_booking_confirmation($booking_id, $recipient_email) {
    global $mysqli;
    
    // Get booking details
    $booking = get_booking_by_id($booking_id);
    if (!$booking) return false;
    
    // Get room and area info
    $room_name = get_room_name($booking['room_id']);
    $area_name = get_area_name($booking['area_id'] ?? 0);
    
    // Prepare email content
    $subject = "Booking Confirmation: " . $booking['name'];
    
    // Check if times were adjusted
    $adjustment_note = '';
    if (!empty($booking['time_adjustments'])) {
        $adjustments = json_decode($booking['time_adjustments'], true);
        if (!empty($adjustments)) {
            $adjustment_note = "\n\nNote: Your booking time was automatically adjusted to avoid conflicts with existing bookings.";
            foreach ($adjustments as $adjustment) {
                if (isset($adjustment['message'])) {
                    $adjustment_note .= "\n- " . $adjustment['message'];
                }
            }
        }
    }
    
    // Build the email body
    $email_header = "New Meeting Scheduled";
    $headline = "scheduled";
    $room_display = $area_name . ' - ' . $room_name;
    
    $start_time_formatted = date('F j, Y g:i A', $booking['start_time']);
    $end_time_formatted = date('F j, Y g:i A', $booking['end_time']);
    
    $duration = $booking['end_time'] - $booking['start_time'];
    $duration_hours = floor($duration / 3600);
    $duration_minutes = floor(($duration % 3600) / 60);
    $duration_string = sprintf("%d hours %d minutes", $duration_hours, $duration_minutes);
    
    $confirmed = $booking['status'] == 0 ? 'Confirmed' : 'Tentative';
    $type = $booking['type'] == 'I' ? 'Internal' : 'External';
    
    $message = <<<HTML
    <html>
      <head>
        <style>
          body { font-family: Arial, sans-serif; color: #333; }
          .container { padding: 20px; border: 1px solid #e0e0e0; background-color: #f9f9f9; max-width: 600px; margin: auto; }
          h2 { color: #2c3e50; }
          .details { background-color: #fff; border: 1px solid #ddd; padding: 15px; margin-top: 15px; }
          .adjustment-note { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; }
          .footer { font-size: 12px; color: #888; margin-top: 20px; }
        </style>
      </head>
      <body>
        <div class="container">
          <h2>{$email_header}</h2>
          <p>Good day</p>
          <p>The following meeting has been <strong>{$headline}</strong> by the organizer:</p>
          <div class="details">
            <p><strong>Subject:</strong> {$booking['name']}</p>
            <p><strong>Description:</strong> {$booking['description']}</p>
            <p><strong>Organizer:</strong> {$booking['create_by']}</p>
            <p><strong>Start Time:</strong> {$start_time_formatted}</p>
            <p><strong>End Time:</strong> {$end_time_formatted}</p>
            <p><strong>Duration:</strong> {$duration_string}</p>
            <p><strong>Type:</strong> {$type}</p>
            <p><strong>Area / Rooms:</strong><br>{$room_display}</p>
            <p><strong>Confirmation status: </strong>{$confirmed}</p>
          </div>
          <div class="footer">
            <p>This is an automated email from the system.</p>
            <p>Please do not reply to this message.</p>
          </div>
        </div>
      </body>
    </html>
    HTML;
    
    // Send email (queue it in email_queue table)
    $stmt = $mysqli->prepare("
        INSERT INTO email_queue (recipient, subject, body, action) 
        VALUES (?, ?, ?, 'booking_confirmation')
    ");
    
    $stmt->bind_param("sss", $recipient_email, $subject, $message);
    $stmt->execute();
    $stmt->close();
    
    return true;
}
?>