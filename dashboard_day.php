<?php
// dashboard_day.php - Daily View
require_once 'dashboard_common.php'; // Common functions and setup

// Get date information for day view
$date_timestamp = strtotime($selected_date);
$day_of_week = date('l', $date_timestamp);
$date_display = date('F j, Y', $date_timestamp);

// Time slots for the day (30-minute intervals from 7 AM to 5 PM)
$time_slots = [];
$current_time = strtotime($selected_date . " 07:00:00");
$end_time = strtotime($selected_date . " 18:00:00");

$slot_count = 0;
while ($current_time < $end_time && $slot_count < 24) {
    $time_slots[] = [
        'time' => date('g:i A', $current_time),
        'timestamp' => $current_time
    ];
    $current_time += 1800; // 30 minutes
    $slot_count++;
}

// Get bookings for selected date if a room is selected
$room_bookings = [];
if ($selected_room > 0) {
    $room_bookings = get_bookings_for_room($selected_room, $selected_date, $selected_date);
}

// Get available slots for quick booking (with buffer)
$available_slots = [];
if ($selected_room > 0) {
    $available_slots = get_available_slots($selected_room, $selected_date);
}

// Get previous and next dates for navigation
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

// Get room name
$room_name = $selected_room > 0 ? get_room_name($selected_room) : '';

// Get area name
$area_name = '';
if ($selected_area > 0) {
    $area_name = get_area_name($selected_area);
} elseif ($selected_room > 0) {
    $area_name = get_area_name_by_room($selected_room);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Availability Dashboard - Daily View - MRBS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        /* Day View Specific Styles */
        .room-grid-day {
            grid-template-columns: 140px repeat(<?php echo min(count($time_slots), 24); ?>, 1fr);
        }
        
        .day-header {
            background: #334155;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 4px;
            min-width: 60px;
        }
    </style>
</head>
<body>
    <?php include 'dashboard_header.php'; ?>
    
    <!-- Calendar Header for Day View -->
    <div class="calendar-header">
        <div class="date-navigation">
            <button class="nav-btn" onclick="navigateDate('-1', 'day')" title="Previous Day">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="current-date">
                <i class="fas fa-calendar-day"></i> <?php echo $date_display; ?>
                <div style="font-size: 1rem; color: #64748b; font-weight: normal; margin-top: 5px;">
                    <i class="fas fa-clock"></i> Viewing 7:00 AM - 5:00 PM
                </div>
            </div>
            <button class="nav-btn" onclick="navigateDate('+1', 'day')" title="Next Day">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="date-controls">
            <!-- View Type Buttons -->
            <div class="view-type-selector">
                <button class="view-type-btn active" onclick="changeViewType('day')" title="Daily View">
                    <i class="fas fa-calendar-day"></i> Day
                </button>
                <button class="view-type-btn" onclick="changeViewType('week')" title="Weekly View">
                    <i class="fas fa-calendar-week"></i> Week
                </button>
                <button class="view-type-btn" onclick="changeViewType('month')" title="Monthly View">
                    <i class="fas fa-calendar-alt"></i> Month
                </button>
            </div>
            
            <button class="today-btn" onclick="goToToday('day')">
                <i class="fas fa-calendar-day"></i> Today
            </button>
            
            <input type="date" id="date_picker" 
                   value="<?php echo $selected_date; ?>" 
                   onchange="changeDate(this.value, 'day')">
        </div>
    </div>

    <!-- Room Content -->
    <?php if ($selected_room > 0): ?>
        <?php include 'dashboard_room_day.php'; ?>
    <?php elseif ($show_all_rooms): ?>
        <?php include 'dashboard_all_rooms_day.php'; ?>
    <?php else: ?>
        <?php include 'dashboard_empty.php'; ?>
    <?php endif; ?>

    <!-- Modals -->
    <?php include 'dashboard_modals.php'; ?>
    
    <script src="js/dashboard_common.js"></script>
    <script src="js/dashboard_day.js"></script>
</body>
</html>