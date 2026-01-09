<?php
// dashboard_common.php - Common functions and variables
date_default_timezone_set('Asia/Manila');

// Get parameters (these come from dashboard.php router)
$selected_date = $selected_date ?? date('Y-m-d');
$selected_area = $selected_area ?? 0;
$selected_room = $selected_room ?? 0;
$view_type = $view_type ?? 'day';

// Get areas
$areas = get_areas();
$show_all_rooms = false;
$all_rooms_grouped = [];

// Get rooms based on selection
if ($selected_area > 0 && $selected_room == 0) {
    // Specific area selected, All Rooms chosen - show all rooms in that area
    $rooms = get_rooms_by_area($selected_area);
    
    // Create grouped structure for the specific area
    $area_info = get_area_info($selected_area);
    $all_rooms_grouped = [
        [
            'area_id' => $selected_area,
            'area_name' => $area_info['area_name'] ?? 'Unknown Area',
            'rooms' => $rooms
        ]
    ];
    $show_all_rooms = true;
} elseif ($selected_area == 0 && $selected_room == 0) {
    // All Areas and All Rooms selected - show all rooms from all areas
    $all_rooms_grouped = get_all_rooms_grouped_by_area();
    $show_all_rooms = true;
    $rooms = get_all_rooms(); // For dropdown
} elseif ($selected_area > 0 && $selected_room > 0) {
    // Specific room selected
    $rooms = get_rooms_by_area($selected_area);
    $show_all_rooms = false;
} else {
    // All Areas, specific room (or other combinations)
    $rooms = get_all_rooms();
    $show_all_rooms = false;
}

// Today date
$today = date('Y-m-d');

// Check if user is logged in
$is_logged_in = is_logged_in();
$current_user_name = $is_logged_in ? get_current_user_name() : 'Guest';
$current_user = $is_logged_in ? get_current_username() : null;

// Common functions
function get_date_range_for_view($view_type, $selected_date) {
    switch ($view_type) {
        case 'week':
            $date_timestamp = strtotime($selected_date);
            $week_start = date('Y-m-d', strtotime('monday this week', $date_timestamp));
            $week_end = date('Y-m-d', strtotime('sunday this week', $date_timestamp));
            return [
                'start' => $week_start,
                'end' => $week_end,
                'display' => date('F j', strtotime($week_start)) . ' - ' . date('j, Y', strtotime($week_end))
            ];
            
        case 'month':
            $date_timestamp = strtotime($selected_date);
            $month_start = date('Y-m-01', $date_timestamp);
            $month_end = date('Y-m-t', $date_timestamp);
            return [
                'start' => $month_start,
                'end' => $month_end,
                'display' => date('F Y', $date_timestamp)
            ];
            
        default: // day
            return [
                'start' => $selected_date,
                'end' => $selected_date,
                'display' => date('F j, Y', strtotime($selected_date))
            ];
    }
}
?>