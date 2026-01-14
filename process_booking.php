<?php
// process_booking.php
date_default_timezone_set('Asia/Manila');

require_once 'connection.php';
require_login(); // User must be logged in

// Get current user info
$current_user = get_current_username();
$current_user_name = get_current_user_name();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required_fields = ['event_name', 'brief_desc', 'start_time', 'end_time', 'room_id', 'event_type'];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $error_message = "Please fill in all required fields. Missing: " . $field;
            break;
        }
    }
    
    // Use the timestamps from the form
    $start_datetime = (int)$_POST['start_time'];
    $end_datetime = (int)$_POST['end_time'];
    
    if ($end_datetime <= $start_datetime) {
        $error_message = "End time must be after start time.";
    }
    
    // If external event, require representative and preparations
    if ($_POST['event_type'] === 'E') {
        if (empty($_POST['representative'])) {
            $error_message = "Representative name is required for external events.";
        }
        
        // Check if at least one preparation is selected
        $has_preparations = false;
        if (!empty($_POST['needs_water']) || !empty($_POST['needs_whiteboard']) || 
            !empty($_POST['needs_coffee']) || !empty($_POST['needs_projector']) || 
            !empty($_POST['needs_snacks']) || !empty($_POST['other_preparations'])) {
            $has_preparations = true;
        }
    }
    
    if (!isset($error_message)) {
        // Get form data
        $event_name = sanitize_input($_POST['event_name']);
        $brief_desc = sanitize_input($_POST['brief_desc']);
        $full_desc = !empty($_POST['full_desc']) ? sanitize_input($_POST['full_desc']) : '';
        $room_id = (int)$_POST['room_id'];
        $area_id = (int)$_POST['area_id'];
        $event_type = $_POST['event_type']; // 'I' for internal, 'E' for external
        $confirmation_status = $_POST['confirmation_status'] ?? 'confirmed';
        $notification_emails = $_POST['notification_emails'] ?? '';
        
        // Build description
        $description = $brief_desc;
        if (!empty($full_desc)) {
            $description .= "\n\n" . $full_desc;
        }
        
        // Prepare preparations for external events
        $preparations = [];
        if ($event_type === 'E') {
            // Representative
            $representative = sanitize_input($_POST['representative']);
            
            // Preparations from checkboxes
            if (!empty($_POST['needs_water'])) $preparations[] = 'Water';
            if (!empty($_POST['needs_whiteboard'])) $preparations[] = 'Whiteboard';
            if (!empty($_POST['needs_coffee'])) $preparations[] = 'Coffee';
            if (!empty($_POST['needs_projector'])) $preparations[] = 'Projector';
            if (!empty($_POST['needs_snacks'])) $preparations[] = 'Snacks';
            
            // Other preparations
            if (!empty($_POST['other_preparations'])) {
                $other_preps = explode(',', $_POST['other_preparations']);
                foreach ($other_preps as $prep) {
                    $prep = trim($prep);
                    if (!empty($prep)) {
                        $preparations[] = $prep;
                    }
                }
            }
            
            // Add preparations to description
            if (!empty($preparations)) {
                $description .= "\n\nPreparations needed: " . implode(', ', $preparations);
            }
            if (!empty($representative)) {
                $description .= "\nRepresentative: " . $representative;
            }
        }
        
        // Create booking data
        $booking_data = [
            'start_time' => $start_datetime,
            'end_time' => $end_datetime,
            'room_id' => $room_id,
            'event_name' => $event_name,
            'event_type' => $event_type,
            'description' => $description,
            'status' => ($confirmation_status === 'confirmed') ? 0 : 1
        ];
        
        // For external events, add representative and preparations
        if ($event_type === 'E') {
            $booking_data['representative_name'] = $representative ?? '';
            $booking_data['representative_email'] = ''; // No email for representative
            if (!empty($preparations))
                $booking_data['preparations'] = $preparations;
            
            create_welcome($representative, $room_id, $_POST['start_date'], $_POST['end_date']);
        }
        
        // Add notification emails if provided
        if (!empty($notification_emails)) {
            $booking_data['notification_email'] = $notification_emails;
        }
        
        // Call create_booking function from connection.php
        $result = create_booking($booking_data);
        
        if ($result['success']) {
            // Build success URL with adjustment info
            $success_url = 'dashboard.php?success=1&booking_id=' . $result['booking_id'];
            
            // Add adjustment info if times were adjusted
            if (!empty($result['adjustments'])) {
                $adjustment_messages = [];
                foreach ($result['adjustments'] as $adjustment) {
                    $adjustment_messages[] = urlencode($adjustment['message']);
                }
                $success_url .= '&adjustments=' . implode('|', $adjustment_messages);
                
                // Add adjusted times for display
                $adjusted_start = date('g:i A', $result['adjusted_times']['start']);
                $adjusted_end = date('g:i A', $result['adjusted_times']['end']);
                $original_start = date('g:i A', $result['adjusted_times']['original_start']);
                $original_end = date('g:i A', $result['adjusted_times']['original_end']);
                
                $success_url .= '&adjusted_start=' . urlencode($adjusted_start);
                $success_url .= '&adjusted_end=' . urlencode($adjusted_end);
                $success_url .= '&original_start=' . urlencode($original_start);
                $success_url .= '&original_end=' . urlencode($original_end);
                $success_url .= '&has_adjustments=1';
            }
            
            // Send email notifications to all provided emails
            if (!empty($notification_emails)) {
                send_booking_notification($result['booking_id'], 'created', $notification_emails);
            }
            
            // Redirect to dashboard with success message
            header('Location: ' . $success_url);
            exit();
        } else {
            $error_message = $result['message'];
            header('Location: dashboard.php?error=' . urlencode($error_message));
            exit();
        }
    } else {
        // Redirect back with error message
        header('Location: dashboard.php?error=' . urlencode($error_message));
        exit();
    }
} else {
    // If not POST request, redirect to dashboard
    header('Location: dashboard.php');
    exit();
}
?>