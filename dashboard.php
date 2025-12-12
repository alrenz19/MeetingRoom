<?php
// dashboard.php
date_default_timezone_set('Asia/Manila');

require_once 'connection.php';

// Get current date or selected date
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_area = isset($_GET['area']) ? (int)$_GET['area'] : 0;
$selected_room = isset($_GET['room']) ? (int)$_GET['room'] : 0;

// Get date information
$date_timestamp = strtotime($selected_date);
$day_of_week = date('l', $date_timestamp);
$date_display = date('F j, Y', $date_timestamp);

// Get areas
$areas = get_areas();

// Get rooms (either all or filtered by area)
if ($selected_area > 0) {
    $rooms = get_rooms_by_area($selected_area);
} else {
    $rooms = get_all_rooms();
}

// Time slots for the day (30-minute intervals from 8 AM to 8 PM)
$time_slots = [];
$current_time = strtotime($selected_date . " 08:00:00");
$end_time = strtotime($selected_date . " 16:00:00");

$slot_count = 0;
while ($current_time < $end_time && $slot_count < 24) {
    $time_slots[] = [
        'time' => date('H:i', $current_time),
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
$today = date('Y-m-d');

// Get room name
$room_name = $selected_room > 0 ? get_room_name($selected_room) : '';

// Get area name
$area_name = '';
if ($selected_area > 0) {
    $area_name = get_area_name($selected_area);
} elseif ($selected_room > 0) {
    // Get area name from room
    $room_info = get_booking_by_id($selected_room);
    if ($room_info && isset($room_info['area_name'])) {
        $area_name = $room_info['area_name'];
    }
}

// Check if user is logged in
$is_logged_in = is_logged_in();
$current_user_name = $is_logged_in ? get_current_user_name() : 'Guest';
$current_user = $is_logged_in ? get_current_username() : null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Availability Dashboard - MRBS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f0f4f8;
    min-height: 100vh;
    color: #333;
    overflow-x: hidden;
}

.container {
    max-width: 1600px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    overflow: hidden;
    min-height: 100vh;
}

/* Header Styles */
header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 25px 30px;
    position: relative;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.logo-section h1 {
    font-size: 2.4rem;
    margin-bottom: 8px;
    font-weight: 700;
    letter-spacing: -0.5px;
}

.logo-section .subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
    font-weight: 400;
}

.user-info {
    text-align: right;
    font-size: 0.95rem;
    opacity: 0.9;
}

.main-content {
    display: grid;
    grid-template-columns: 300px 1fr;
    min-height: calc(100vh - 150px);
}

/* Sidebar Styles */
.sidebar {
    background: #f8fafc;
    padding: 30px 25px;
    border-right: 1px solid #e2e8f0;
    position: sticky;
    top: 0;
    height: calc(100vh - 150px);
    overflow-y: auto;
}

.filters-section h2 {
    color: #1e3a8a;
    margin-bottom: 25px;
    font-size: 1.5rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e2e8f0;
}

.filter-group {
    margin-bottom: 25px;
}

.filter-group label {
    display: block;
    margin-bottom: 10px;
    font-weight: 600;
    color: #475569;
    font-size: 0.95rem;
}

.filter-group select {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    color: #334155;
    transition: all 0.3s;
    cursor: pointer;
}

.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Legend Styles */
.legend-section {
    margin-top: 40px;
    padding-top: 25px;
    border-top: 1px solid #e2e8f0;
}

.legend-section h3 {
    color: #1e3a8a;
    margin-bottom: 18px;
    font-size: 1.2rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.legend-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    flex-shrink: 0;
}

.legend-available { background: #10b981; }
.legend-booked { background: #ef4444; }
.legend-internal { background: #3b82f6; }
.legend-external { background: #f59e0b; }
.legend-tentative { background: #8b5cf6; }

.legend-text {
    font-size: 0.9rem;
    color: #475569;
    font-weight: 500;
}

/* Content Area Styles */
.content {
    padding: 30px;
    background: #f8fafc;
    height: calc(100vh - 150px);
    overflow-y: auto;
}

/* Calendar Header */
.calendar-header {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.date-navigation {
    display: flex;
    align-items: center;
    gap: 15px;
}

.nav-btn {
    background: #3b82f6;
    color: white;
    border: none;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
    font-size: 1.1rem;
}

.nav-btn:hover {
    background: #2563eb;
    transform: scale(1.05);
}

.current-date {
    font-size: 1.8rem;
    font-weight: 700;
    color: #1e3a8a;
    min-width: 300px;
}

.date-controls {
    display: flex;
    gap: 15px;
    align-items: center;
}

.today-btn {
    background: #10b981;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.today-btn:hover {
    background: #0da271;
    transform: translateY(-2px);
}

#date_picker {
    padding: 11px 15px;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
    color: #334155;
    min-width: 180px;
}

/* Room Info Card */
.room-info-card {
    background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.2);
}

.room-info-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.room-title {
    font-size: 1.8rem;
    font-weight: 700;
}

.room-status {
    background: rgba(255, 255, 255, 0.2);
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.room-info-details {
    font-size: 1.1rem;
    opacity: 0.9;
}

/* Room Grid - FIXED VERSION */
.room-grid-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    overflow: visible;
    max-height: 400px;
}

.room-grid {
    display: grid;
    grid-template-columns: 140px repeat(<?php echo min(count($time_slots), 24); ?>, 1fr);
    gap: 2px;
    min-width: 800px;
}

.grid-header {
    background: #1e293b;
    color: white;
    padding: 15px;
    text-align: center;
    font-weight: 600;
    font-size: 0.95rem;
    border-radius: 6px;
    position: sticky;
    left: 0;
    z-index: 10;
}

.time-header {
    background: #334155;
    color: white;
    padding: 12px 8px;
    text-align: center;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 4px;
    min-width: 60px;
}

.room-cell {
    background: #f1f5f9;
    padding: 20px 15px;
    text-align: center;
    font-weight: 600;
    color: #1e293b;
    font-size: 1.1rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: sticky;
    left: 0;
    z-index: 10;
}

.time-cell {
    position: relative;
    background: white;
    min-height: 65px;
    min-width: 60px;
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
    border-radius: 6px;
}

.time-cell:hover {
    background: #f0f9ff;
    border-color: #dbeafe;
}

.time-cell.available {
    background: #d1fae5;
    cursor: pointer;
}

.time-cell.available:hover {
    background: #a7f3d0;
    transform: scale(1.02);
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.time-cell.booked {
    background: #fee2e2;
    cursor: not-allowed;
}

.booking-event {
    position: absolute;
    top: 3px;
    left: 3px;
    right: 3px;
    bottom: 3px;
    color: white;
    border-radius: 4px;
    padding: 8px;
    font-size: 0.85rem;
    overflow: hidden;
    z-index: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.booking-event.internal {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
}

.booking-event.external {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}

.booking-event.tentative {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    opacity: 0.9;
    border: 2px dashed rgba(255, 255, 255, 0.5);
}

.event-title {
    font-weight: 600;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 0.8rem;
}

.event-time {
    font-size: 0.7rem;
    opacity: 0.9;
}

/* Quick Book Panel - FIXED VERSION */
.quick-book-panel {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-top: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    border-left: 5px solid #3b82f6;
    overflow: visible;
    max-height: 400px;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}

.panel-title {
    font-size: 1.5rem;
    color: #1e3a8a;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.slot-count {
    background: #3b82f6;
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
}

.available-slots-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
    max-height: 250px;
    overflow-y: auto;
    padding-right: 10px;
}

/* Custom scrollbar for slots grid */
.available-slots-grid::-webkit-scrollbar {
    width: 8px;
}

.available-slots-grid::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.available-slots-grid::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.available-slots-grid::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.slot-card {
    background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%);
    border: 2px solid #dbeafe;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
    min-height: 100px;
}

.slot-card:hover {
    border-color: #3b82f6;
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.2);
}

.slot-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: #3b82f6;
}

.slot-time {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1e40af;
    margin-bottom: 8px;
}

.slot-duration {
    font-size: 0.85rem;
    color: #475569;
    font-weight: 500;
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-top: 20px;
}

.book-btn {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
    flex: 1;
    justify-content: center;
}

.book-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
}

.custom-btn {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
}

.custom-btn:hover {
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 30px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-top: 30px;
}

.empty-state-icon {
    font-size: 4rem;
    color: #cbd5e1;
    margin-bottom: 25px;
}

.empty-state h3 {
    color: #475569;
    margin-bottom: 15px;
    font-size: 1.8rem;
    font-weight: 600;
}

.empty-state p {
    color: #64748b;
    font-size: 1.1rem;
    max-width: 500px;
    margin: 0 auto 25px;
}

/* Success/Error Messages */
.notification {
    position: fixed;
    top: 30px;
    right: 30px;
    z-index: 1000;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.notification-content {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    display: flex;
    align-items: center;
    gap: 15px;
    border-left: 5px solid;
    min-width: 350px;
    max-width: 500px;
}

.notification.success {
    border-left-color: #10b981;
}

.notification.error {
    border-left-color: #ef4444;
}

.notification.adjustment {
    border-left-color: #f59e0b;
}

.notification-icon {
    font-size: 1.5rem;
}

.notification.success .notification-icon {
    color: #10b981;
}

.notification.error .notification-icon {
    color: #ef4444;
}

.notification.adjustment .notification-icon {
    color: #f59e0b;
}

.notification-text h4 {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 1.1rem;
}

/* Adjustment Notification Styles */
.adjustment-details {
    margin-top: 10px;
    padding: 10px;
    background: #fffbeb;
    border-radius: 6px;
    border: 1px solid #fef3c7;
}

.adjustment-details p {
    margin: 5px 0;
    color: #92400e;
    font-size: 0.9rem;
}

.time-comparison {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 10px 0;
    padding: 10px;
    background: #fef3c7;
    border-radius: 6px;
}

.original-time, .adjusted-time {
    display: flex;
    align-items: center;
    gap: 5px;
}

.original-time {
    color: #92400e;
}

.adjusted-time {
    color: #059669;
}

.arrow {
    color: #6b7280;
    font-size: 1.2rem;
}

.buffer-note {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
}

.notification-close {
    background: none;
    border: none;
    color: #64748b;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 0;
    margin-left: auto;
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.3s ease;
    overflow-y: auto;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal {
    background: white;
    width: 500px;
    max-width: 90%;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    animation: slideUp 0.3s ease;
    max-height: 90vh;
    overflow-y: auto;
}

@keyframes slideUp {
    from { transform: translateY(50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-header {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
}

.modal-header h2 {
    color: #1e3a8a;
    font-size: 1.8rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #475569;
    font-size: 0.95rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    font-size: 1rem;
    transition: all 0.3s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.slot-info {
    background: #f8fafc;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid #3b82f6;
}

.slot-info strong {
    color: #1e40af;
    font-size: 1.1rem;
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
}

.btn-cancel {
    flex: 1;
    padding: 14px;
    background: #f1f5f9;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    color: #475569;
    transition: all 0.3s;
}

.btn-cancel:hover {
    background: #e2e8f0;
}

.btn-submit {
    flex: 1;
    padding: 14px;
    background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
}

/* Responsive Design */
@media (max-width: 1200px) {
    .main-content {
        grid-template-columns: 1fr;
        min-height: auto;
    }
    
    .sidebar {
        border-right: none;
        border-bottom: 1px solid #e2e8f0;
        height: auto;
        position: static;
    }
    
    .content {
        height: auto;
        min-height: 600px;
    }
    
    .room-grid {
        min-width: 1000px;
    }
}

@media (max-width: 992px) {
    .room-grid {
        grid-template-columns: 120px repeat(<?php echo min(count($time_slots), 20); ?>, 1fr);
        min-width: 800px;
    }
    
    .room-grid-container {
        max-height: 350px;
    }
    
    .available-slots-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    }
}

@media (max-width: 768px) {
    body {
        padding: 10px;
    }
    
    .container {
        margin: 0;
        border-radius: 8px;
    }
    
    header {
        padding: 20px;
    }
    
    .logo-section h1 {
        font-size: 1.8rem;
    }
    
    .header-content {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .user-info {
        text-align: center;
        width: 100%;
    }
    
    .calendar-header {
        flex-direction: column;
        gap: 20px;
        padding: 20px;
    }
    
    .date-navigation {
        width: 100%;
        justify-content: center;
    }
    
    .current-date {
        min-width: auto;
        text-align: center;
        font-size: 1.5rem;
    }
    
    .date-controls {
        width: 100%;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .room-grid {
        min-width: 700px;
        grid-template-columns: 100px repeat(<?php echo min(count($time_slots), 16); ?>, 1fr);
    }
    
    .room-grid-container {
        padding: 15px;
        max-height: 300px;
    }
    
    .available-slots-grid {
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        max-height: 200px;
    }
    
    .slot-card {
        padding: 15px;
        min-height: 80px;
    }
    
    .slot-time {
        font-size: 1rem;
    }
    
    .modal {
        width: 95%;
        padding: 20px;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}

@media (max-width: 480px) {
    .room-grid {
        min-width: 600px;
        grid-template-columns: 80px repeat(<?php echo min(count($time_slots), 12); ?>, 1fr);
    }
    
    .room-grid-container {
        max-height: 250px;
    }
    
    .room-cell, .grid-header {
        padding: 10px;
        font-size: 0.9rem;
    }
    
    .time-header {
        padding: 8px 4px;
        font-size: 0.75rem;
        min-width: 50px;
    }
    
    .time-cell {
        min-height: 50px;
        min-width: 50px;
    }
    
    .slot-card {
        padding: 12px;
    }
    
    .slot-time {
        font-size: 0.9rem;
    }
}
</style>
</head>
<body>
    <div class="container">
        <!-- Success/Error Notifications -->
        <?php if (isset($_GET['success']) && $_GET['success'] == 1 && isset($_GET['booking_id'])): ?>
            <?php if (isset($_GET['has_adjustments']) && $_GET['has_adjustments'] == 1): ?>
                <div class="notification adjustment">
                    <div class="notification-content">
                        <div class="notification-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="notification-text">
                            <h4>Booking Created with Time Adjustment</h4>
                            <p>Booking ID: <?php echo htmlspecialchars($_GET['booking_id']); ?> has been created.</p>
                            
                            <?php if (isset($_GET['adjustments'])): ?>
                                <div class="adjustment-details">
                                    <p><strong><i class="fas fa-info-circle"></i> Time Adjustments Applied:</strong></p>
                                    <?php 
                                    $adjustments = explode('|', $_GET['adjustments']);
                                    foreach ($adjustments as $adjustment):
                                        echo '<p>' . htmlspecialchars(urldecode($adjustment)) . '</p>';
                                    endforeach;
                                    ?>
                                    
                                    <?php if (isset($_GET['original_start']) && isset($_GET['adjusted_start'])): ?>
                                    <div class="time-comparison">
                                        <div class="original-time">
                                            <i class="fas fa-clock"></i>
                                            <span>Original: <?php echo htmlspecialchars(urldecode($_GET['original_start'])); ?> - <?php echo htmlspecialchars(urldecode($_GET['original_end'])); ?></span>
                                        </div>
                                        <div class="arrow">
                                            <i class="fas fa-arrow-right"></i>
                                        </div>
                                        <div class="adjusted-time">
                                            <i class="fas fa-clock"></i>
                                            <span>Booked: <?php echo htmlspecialchars(urldecode($_GET['adjusted_start'])); ?> - <?php echo htmlspecialchars(urldecode($_GET['adjusted_end'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="buffer-note">
                                        <i class="fas fa-info-circle"></i> A 2-minute buffer was applied between bookings to allow for room preparation and transition.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="notification success">
                    <div class="notification-content">
                        <div class="notification-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="notification-text">
                            <h4>Booking Successful!</h4>
                            <p>Booking ID: <?php echo htmlspecialchars($_GET['booking_id']); ?> has been created.</p>
                        </div>
                        <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notification error">
                <div class="notification-content">
                    <div class="notification-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="notification-text">
                        <h4>Booking Error</h4>
                        <p><?php echo htmlspecialchars(urldecode($_GET['error'])); ?></p>
                    </div>
                    <button class="notification-close" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <header>
            <div class="header-content">
                <div class="logo-section">
                    <h1><i class="fas fa-calendar-alt"></i> Room Availability Dashboard</h1>
                    <p class="subtitle">View and book available rooms in real-time</p>
                </div>
                <div class="user-info">
                    <p><i class="fas fa-user"></i> 
                        <?php if ($is_logged_in): ?>
                            Logged in as: <?php echo htmlspecialchars($current_user_name); ?>
                            <a href="logout.php" style="color: white; margin-left: 10px;">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        <?php else: ?>
                            Guest User
                            <a href="login.php" style="color: white; margin-left: 10px;">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </a>
                        <?php endif; ?>
                    </p>
                    <p><i class="fas fa-clock"></i> <?php echo date('l, F j, Y'); ?></p>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Filters Section -->
                <div class="filters-section">
                    <h2><i class="fas fa-filter"></i> Filters & Selection</h2>
                    
                    <div class="filter-group">
                        <label for="area_filter"><i class="fas fa-building"></i> Select Area</label>
                        <select id="area_filter" name="area" onchange="filterRooms()">
                            <option value="0">All Areas</option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?php echo $area['id']; ?>" 
                                    <?php echo $selected_area == $area['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($area['area_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="room_filter"><i class="fas fa-door-closed"></i> Select Room</label>
                        <select id="room_filter" name="room" onchange="updateDashboard()">
                            <option value="0">-- Select a Room --</option>
                            <?php foreach ($rooms as $room): 
                                $room_label = isset($room['area_name']) 
                                    ? $room['room_name'] . ' (' . $room['area_name'] . ')'
                                    : $room['room_name'];
                            ?>
                                <option value="<?php echo $room['id']; ?>" 
                                    <?php echo $selected_room == $room['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Legend Section -->
                <div class="legend-section">
                    <h3><i class="fas fa-info-circle"></i> Color Legend</h3>
                    <div class="legend-grid">
                        <div class="legend-item">
                            <div class="legend-color legend-available"></div>
                            <span class="legend-text">Available Slot</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-booked"></div>
                            <span class="legend-text">Booked Slot</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-internal"></div>
                            <span class="legend-text">Internal Event</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-external"></div>
                            <span class="legend-text">External Event</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-tentative"></div>
                            <span class="legend-text">Tentative Booking</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <div class="content">
                <!-- Calendar Header -->
                <div class="calendar-header">
                    <div class="date-navigation">
                        <button class="nav-btn" onclick="navigateDate('-1')" title="Previous Day">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="current-date">
                            <i class="fas fa-calendar-day"></i> <?php echo $date_display; ?>
                            <div style="font-size: 1rem; color: #64748b; font-weight: normal; margin-top: 5px;">
                                <i class="fas fa-clock"></i> Viewing 8:00 AM - 8:00 PM
                            </div>
                        </div>
                        <button class="nav-btn" onclick="navigateDate('+1')" title="Next Day">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    
                    <div class="date-controls">
                        <button class="today-btn" onclick="goToToday()">
                            <i class="fas fa-calendar-day"></i> Today
                        </button>
                        
                        <input type="date" id="date_picker" 
                               value="<?php echo $selected_date; ?>" 
                               onchange="changeDate()">
                    </div>
                </div>

                <?php if ($selected_room > 0): ?>
                    <!-- Room Information Card -->
                    <div class="room-info-card">
                        <div class="room-info-header">
                            <div class="room-title">
                                <i class="fas fa-door-open"></i> <?php echo htmlspecialchars($room_name); ?>
                                <?php if ($area_name): ?>
                                    <span style="font-size: 1rem; font-weight: normal; opacity: 0.9;">
                                        (<?php echo htmlspecialchars($area_name); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="room-status">
                                <i class="fas fa-chart-bar"></i> 
                                <?php echo count($available_slots); ?> slots available
                            </div>
                        </div>
                        <div class="room-info-details">
                            <i class="fas fa-calendar-check"></i> 
                            Viewing availability for <?php echo $day_of_week; ?>, <?php echo $date_display; ?>
                            <?php if (!$is_logged_in): ?>
                                <span style="color: #fbbf24; margin-left: 10px;">
                                    <i class="fas fa-exclamation-triangle"></i> Login required to book
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Room Grid -->
                    <div class="room-grid-container">
                        <div class="room-grid" style="grid-template-columns: 140px repeat(<?php echo count($time_slots); ?>, 1fr);">
                            <!-- Grid Headers -->
                            <div class="grid-header">Time / Room</div>
                            <?php foreach ($time_slots as $slot): ?>
                                <div class="time-header"><?php echo $slot['time']; ?></div>
                            <?php endforeach; ?>
                            
                            <!-- Room Row -->
                            <div class="room-cell">
                                <?php echo htmlspecialchars($room_name); ?>
                            </div>
                            
                            <!-- Time Cells -->
                            <?php foreach ($time_slots as $slot): 
                                $slot_start = $slot['timestamp'];
                                $slot_end = $slot_start + 1800;
                                $is_booked = false;
                                $booking_info = null;
                                
                                // Check if this slot is booked (with 2-minute buffer)
                                $buffer = 120; // 2 minutes in seconds
                                foreach ($room_bookings as $booking) {
                                    // Check for overlap considering buffer
                                    if (!(($slot_end - $buffer) <= $booking['start_time'] || 
                                          ($slot_start + $buffer) >= $booking['end_time'])) {
                                        $is_booked = true;
                                        $booking_info = $booking;
                                        break;
                                    }
                                }
                            ?>
                                <div class="time-cell <?php echo $is_booked ? 'booked' : 'available'; ?>" 
                                     data-start="<?php echo date('H:i', $slot_start); ?>"
                                     data-start-timestamp="<?php echo $slot_start; ?>"
                                     data-end="<?php echo date('H:i', $slot_end); ?>"
                                     data-end-timestamp="<?php echo $slot_end; ?>"
                                     onclick="<?php echo !$is_booked ? ($is_logged_in ? 'selectSlot(this)' : 'showLoginAlert()') : ''; ?>"
                                     title="<?php echo !$is_booked ? ($is_logged_in ? 'Click to book this slot (2-minute buffer between bookings)' : 'Login to book') : 'Already booked'; ?>">
                                    
                                    <?php if ($is_booked && $booking_info): ?>
                                        <div class="booking-event <?php 
                                            echo $booking_info['type'] == 'I' ? 'internal' : 'external';
                                            echo $booking_info['status'] == 1 ? ' tentative' : '';
                                        ?>">
                                            <div class="event-title">
                                                <?php echo htmlspecialchars(substr($booking_info['name'], 0, 18)); ?>
                                            </div>
                                            <div class="event-time">
                                                <?php 
                                                // Convert Unix timestamp to readable time in Manila timezone
                                                $start_time = date('H:i', $booking_info['start_time']);
                                                $end_time = date('H:i', $booking_info['end_time']);
                                                echo $start_time . ' - ' . $end_time; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php elseif (!$is_booked): ?>
                                        <div style="padding: 8px; color: #065f46; font-weight: 500;">
                                            <i class="fas fa-check-circle"></i> Available
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Book Panel -->
                    <?php if (!empty($available_slots)): ?>
                        <div class="quick-book-panel">
                            <div class="panel-header">
                                <div class="panel-title">
                                    <i class="fas fa-bolt"></i> Available Slots
                                </div>
                                <div class="slot-count">
                                    <?php echo count($available_slots); ?> slots
                                </div>
                            </div>
                            
                            <div class="available-slots-grid">
                                <?php foreach ($available_slots as $slot): ?>
                                    <div class="slot-card" 
                                         data-start="<?php echo $slot['formatted_start']; ?>"
                                         data-end="<?php echo $slot['formatted_end']; ?>"
                                         data-start-timestamp="<?php echo strtotime($selected_date . ' ' . $slot['formatted_start']); ?>"
                                         data-end-timestamp="<?php echo strtotime($selected_date . ' ' . $slot['formatted_end']); ?>"
                                         onclick="<?php echo $is_logged_in ? 'selectAvailableSlot(this)' : 'showLoginAlert()'; ?>"
                                         title="Click to book this slot (2-minute buffer between bookings)">
                                        <div class="slot-time">
                                            <?php echo $slot['formatted_start']; ?> - <?php echo $slot['formatted_end']; ?>
                                        </div>
                                        <div class="slot-duration">
                                            30-minute slot
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="action-buttons">
                                <button class="book-btn custom-btn" onclick="<?php echo $is_logged_in ? 'openCustomBookingForm()' : 'showLoginAlert()'; ?>">
                                    <i class="fas fa-calendar-plus"></i> Book Meeting Room
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="quick-book-panel" style="border-left-color: #ef4444;">
                            <div style="text-align: center; padding: 40px 20px;">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; color: #ef4444; margin-bottom: 20px;"></i>
                                <h3 style="color: #1e293b; margin-bottom: 15px; font-size: 1.5rem;">No Available Slots</h3>
                                <p style="color: #64748b; margin-bottom: 25px; max-width: 500px; margin-left: auto; margin-right: auto;">
                                    This room is fully booked for <?php echo $date_display; ?>. Please try another date or room.
                                </p>
                                <div class="action-buttons">
                                    <button class="book-btn" onclick="navigateDate('+1')">
                                        <i class="fas fa-arrow-right"></i> Check Next Day
                                    </button>
                                    <button class="book-btn custom-btn" onclick="filterRooms()">
                                        <i class="fas fa-door-open"></i> Try Another Room
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <!-- Empty State - No Room Selected -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-door-open"></i>
                        </div>
                        <h3>Select a Room to View Availability</h3>
                        <p>Choose a room from the dropdown menu to see its booking schedule and available time slots for today.</p>
                        <p style="font-size: 0.95rem; color: #94a3b8; margin-top: 20px;">
                            <i class="fas fa-lightbulb"></i> Tip: You can filter rooms by area to find the perfect meeting space.
                        </p>
                        <?php if (!$is_logged_in): ?>
                            <div style="margin-top: 30px; padding: 20px; background: #fef3c7; border-radius: 8px; border: 1px solid #fbbf24; max-width: 400px; margin-left: auto; margin-right: auto;">
                                <p style="color: #92400e; margin-bottom: 10px;">
                                    <i class="fas fa-info-circle"></i> <strong>Note:</strong> You need to login to book rooms
                                </p>
                                <a href="login.php" style="display: inline-flex; align-items: center; gap: 8px; background: #3b82f6; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 500;">
                                    <i class="fas fa-sign-in-alt"></i> Login to Book Rooms
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Booking Modal (Detailed Form) -->
    <div id="bookingModal" class="modal-overlay" onclick="if(event.target === this) closeModal()">
        <div class="modal" style="width: 800px; max-width: 95%; max-height: 90vh; overflow-y: auto;">
            <div class="modal-header">
                <h2><i class="fas fa-calendar-plus"></i> Book Meeting Room</h2>
            </div>
            
            <form id="bookingForm" method="POST" action="process_booking.php">
                <input type="hidden" id="booking_room_id" name="room_id" value="<?php echo $selected_room; ?>">
                <input type="hidden" id="booking_area_id" name="area_id" value="<?php echo $selected_area; ?>">
                <input type="hidden" id="booking_selected_date" name="selected_date" value="<?php echo $selected_date; ?>">
                <input type="hidden" id="booking_start_time" name="start_time">
                <input type="hidden" id="booking_end_time" name="end_time">
                
                <!-- Event Information -->
                <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef2f7;">
                    <h3 style="color: #667eea; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle"></i> Event Information
                    </h3>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-heading"></i> Event Name *
                            </label>
                            <input type="text" name="event_name" required style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" placeholder="Enter event name">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-users"></i> Event Type
                            </label>
                            <select name="event_type" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" onchange="toggleExternalFields()">
                                <option value="I">Internal Meeting</option>
                                <option value="E">External Event</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                            <i class="fas fa-file-alt"></i> Brief Description *
                        </label>
                        <textarea name="brief_desc" required rows="2" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" placeholder="Short description of the event"></textarea>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                            <i class="fas fa-align-left"></i> Full Description (Optional)
                        </label>
                        <textarea name="full_desc" rows="3" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" placeholder="Detailed description of the event"></textarea>
                    </div>
                </div>

                <!-- Date & Time Information -->
                <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef2f7;">
                    <h3 style="color: #667eea; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-clock"></i> Date & Time
                    </h3>
                    
                    <div id="selectedSlotInfo" style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #3b82f6;">
                        <strong id="slotDetails">
                            <!-- Will be filled by JavaScript -->
                        </strong>
                    </div>
                    
                    <div id="conflictWarning" style="display: none; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #92400e; margin: 0; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span id="conflictMessage"></span>
                        </p>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-calendar-day"></i> Start Date
                            </label>
                            <input type="date" id="start_date" name="start_date" required style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" 
                                   value="<?php echo $selected_date; ?>">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-clock"></i> Start Time
                            </label>
                            <input type="time" id="start_time_input" name="start_time_input" required style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-calendar-day"></i> End Date
                            </label>
                            <input type="date" id="end_date" name="end_date" required style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;"
                                   value="<?php echo $selected_date; ?>">
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                <i class="fas fa-clock"></i> End Time
                            </label>
                            <input type="time" id="end_time_input" name="end_time_input" required style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;">
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-radius: 8px; text-align: center;">
                        <i class="fas fa-hourglass-half"></i>
                        <span id="durationText" style="font-weight: 500; color: #1e40af;">Duration: </span>
                    </div>
                    
                    <div style="margin-top: 10px; padding: 10px; background: #fffbeb; border-radius: 6px; border: 1px solid #fef3c7;">
                        <p style="color: #92400e; margin: 0; font-size: 0.9rem;">
                            <i class="fas fa-info-circle"></i> Note: A 2-minute buffer is maintained between bookings for room preparation.
                        </p>
                    </div>
                </div>

                <!-- Location Info -->
                <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef2f7;">
                    <h3 style="color: #667eea; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-map-marker-alt"></i> Location
                    </h3>
                    
                    <div style="background: #f8fafc; padding: 15px; border-radius: 8px;">
                        <p><strong>Room:</strong> <span id="selectedRoomName"><?php echo htmlspecialchars($room_name); ?></span></p>
                        <p><strong>Area:</strong> 
                            <?php 
                            if ($selected_area > 0) {
                                echo htmlspecialchars(get_area_name($selected_area));
                            } elseif ($selected_room > 0) {
                                echo htmlspecialchars($area_name);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </p>
                        <p><strong>Booked By:</strong> 
                            <?php 
                            if ($is_logged_in) {
                                echo htmlspecialchars($current_user_name);
                            } else {
                                echo 'Guest (Please login)';
                            }
                            ?>
                        </p>
                    </div>
                </div>

                <!-- External Event Fields (Hidden by default) -->
                <div id="externalFields" style="display: none; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef2f7;">
                    <h3 style="color: #1e40af; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-external-link-alt"></i> External Event Details
                    </h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                            <i class="fas fa-user-tie"></i> Representative Name *
                        </label>
                        <input type="text" id="representative" name="representative" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" placeholder="Enter representative name">
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 15px; font-weight: 500; color: #333;">
                            <i class="fas fa-tools"></i> Things to Prepare (Select at least one)
                        </label>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="needs_water" value="1"> Water
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="needs_whiteboard" value="1"> Whiteboard
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="needs_coffee" value="1"> Coffee
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="needs_projector" value="1"> Projector
                            </label>
                            
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" name="needs_snacks" value="1"> Snacks
                            </label>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                                Other Preparations
                            </label>
                            <textarea id="other_preparations" name="other_preparations" rows="2" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" placeholder="Any other preparation requirements"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Notification Settings -->
                <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef2f7;">
                    <h3 style="color: #667eea; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-bell"></i> Notification Settings
                    </h3>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                            <i class="fas fa-envelope"></i> Send Notification To (Multiple emails, comma separated)
                        </label>
                        <textarea name="notification_emails" rows="2" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;" 
                                  placeholder="email1@example.com, email2@example.com"></textarea>
                        <small style="color: #64748b; display: block; margin-top: 5px;">Enter multiple email addresses separated by commas</small>
                    </div>
                    
                    <div>
                        <label style="display: block; margin-bottom: 15px; font-weight: 500; color: #333;">
                            <i class="fas fa-check-circle"></i> Confirmation Status
                        </label>
                        <div style="display: flex; gap: 20px;">
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="confirmation_status" value="tentative"> Tentative
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px;">
                                <input type="radio" name="confirmation_status" value="confirmed" checked> Confirmed
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Repeat Event -->
                <div style="margin-bottom: 30px;">
                    <h3 style="color: #667eea; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-redo"></i> Repeat Event
                    </h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="repeat_type" value="none" checked onchange="toggleRepeatUntil()"> None
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="repeat_type" value="daily" onchange="toggleRepeatUntil()"> Daily
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="repeat_type" value="weekly" onchange="toggleRepeatUntil()"> Weekly
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="repeat_type" value="monthly" onchange="toggleRepeatUntil()"> Monthly
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="radio" name="repeat_type" value="yearly" onchange="toggleRepeatUntil()"> Yearly
                        </label>
                    </div>
                    
                    <div id="repeatUntilSection" style="display: none;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #333;">
                            Repeat Until
                        </label>
                        <input type="date" id="repeat_until" name="repeat_until" style="width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px;">
                    </div>
                </div>

                <!-- Form Actions -->
                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="button" class="btn-cancel" onclick="closeModal()" style="flex: 1; padding: 14px;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn-submit" id="submitBookingBtn" style="flex: 1; padding: 14px;">
                        <i class="fas fa-check"></i> Confirm Booking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Login Alert Modal -->
    <div id="loginModal" class="modal-overlay" onclick="closeLoginModal()">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-sign-in-alt"></i> Login Required</h2>
            </div>
            <div style="padding: 30px; text-align: center;">
                <i class="fas fa-lock" style="font-size: 4rem; color: #3b82f6; margin-bottom: 20px;"></i>
                <h3 style="color: #1e293b; margin-bottom: 15px; font-size: 1.5rem;">Please Login to Book Rooms</h3>
                <p style="color: #64748b; margin-bottom: 30px;">
                    You need to be logged in to make bookings. Please login or create an account to continue.
                </p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <a href="login.php" style="text-decoration: none;">
                        <button class="btn-submit" style="padding: 12px 30px;">
                            <i class="fas fa-sign-in-alt"></i> Go to Login
                        </button>
                    </a>
                    <button class="btn-cancel" onclick="closeLoginModal()" style="padding: 12px 30px;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let selectedTime = '';
        let isCheckingConflicts = false;
        
        // Show login alert for non-logged in users
        function showLoginAlert() {
            document.getElementById('loginModal').style.display = 'flex';
        }
        
        function closeLoginModal() {
            document.getElementById('loginModal').style.display = 'none';
        }
        
        // Select a specific time slot from the grid
        function selectSlot(element) {
            if (!<?php echo $is_logged_in ? 'true' : 'false'; ?>) {
                showLoginAlert();
                return;
            }
            
            const startTime = element.getAttribute('data-start');
            const endTime = element.getAttribute('data-end');
            const startTimestamp = element.getAttribute('data-start-timestamp');
            const endTimestamp = element.getAttribute('data-end-timestamp');
            
            console.log('Selected slot:', startTime, 'to', endTime, 'timestamps:', startTimestamp, endTimestamp);
            
            openBookingFormWithSlot(startTime, endTime, startTimestamp, endTimestamp);
        }
        
        // Select a slot from the available slots grid
        function selectAvailableSlot(element) {
            if (!<?php echo $is_logged_in ? 'true' : 'false'; ?>) {
                showLoginAlert();
                return;
            }
            
            const startTime = element.getAttribute('data-start');
            const endTime = element.getAttribute('data-end');
            const startTimestamp = element.getAttribute('data-start-timestamp');
            const endTimestamp = element.getAttribute('data-end-timestamp');
            
            console.log('Selected available slot:', startTime, 'to', endTime, 'timestamps:', startTimestamp, endTimestamp);
            
            openBookingFormWithSlot(startTime, endTime, startTimestamp, endTimestamp);
        }
        
        // Open booking form with pre-filled time slot
        function openBookingFormWithSlot(startTime, endTime, startTimestamp, endTimestamp) {
            const roomName = '<?php echo addslashes($room_name); ?>';
            const areaName = '<?php echo addslashes($area_name); ?>';
            const dateDisplay = '<?php echo $date_display; ?>';
            const selectedDate = '<?php echo $selected_date; ?>';
            
            // Update the slot information display
            document.getElementById('slotDetails').innerHTML = `
                <i class="fas fa-door-open"></i> ${roomName}<br>
                <i class="fas fa-building"></i> ${areaName}<br>
                <i class="fas fa-calendar-day"></i> ${dateDisplay}<br>
                <i class="fas fa-clock"></i> ${startTime} - ${endTime}
            `;
            
            // Set the time inputs
            document.getElementById('start_time_input').value = startTime;
            document.getElementById('end_time_input').value = endTime;
            
            // Set the hidden timestamp fields
            document.getElementById('booking_start_time').value = startTimestamp;
            document.getElementById('booking_end_time').value = endTimestamp;
            
            // Hide conflict warning initially
            document.getElementById('conflictWarning').style.display = 'none';
            
            // Update duration display
            calculateDuration();
            
            // Check for conflicts
            checkForConflicts();
            
            // Show the modal
            document.getElementById('bookingModal').style.display = 'flex';
        }
        
        // Open booking form for custom time (default to next available hour)
        function openCustomBookingForm() {
            if (!<?php echo $is_logged_in ? 'true' : 'false'; ?>) {
                showLoginAlert();
                return;
            }
            
            const roomName = '<?php echo addslashes($room_name); ?>';
            const areaName = '<?php echo addslashes($area_name); ?>';
            const dateDisplay = '<?php echo $date_display; ?>';
            const selectedDate = '<?php echo $selected_date; ?>';
            
            document.getElementById('slotDetails').innerHTML = `
                <i class="fas fa-door-open"></i> ${roomName}<br>
                <i class="fas fa-building"></i> ${areaName}<br>
                <i class="fas fa-calendar-day"></i> ${dateDisplay}<br>
                <i class="fas fa-clock"></i> Custom time selection
            `;
            
            // Set default times (next hour from now)
            const now = new Date();
            let nextHour = now.getHours() + 1;
            if (nextHour > 23) nextHour = 0;
            
            const startTime = nextHour.toString().padStart(2, '0') + ':00';
            const endTime = (nextHour + 1).toString().padStart(2, '0') + ':00';
            
            document.getElementById('start_time_input').value = startTime;
            document.getElementById('end_time_input').value = endTime;
            
            // Calculate timestamps for the selected date
            const startDateTimeLocal = new Date(selectedDate + 'T' + startTime);
            const endDateTimeLocal = new Date(selectedDate + 'T' + endTime);
            
            // Convert to timestamps
            const startTimestamp = Math.floor(startDateTimeLocal.getTime() / 1000);
            const endTimestamp = Math.floor(endDateTimeLocal.getTime() / 1000);
            
            document.getElementById('booking_start_time').value = startTimestamp;
            document.getElementById('booking_end_time').value = endTimestamp;
            
            // Hide conflict warning initially
            document.getElementById('conflictWarning').style.display = 'none';
            
            // Calculate duration
            calculateDuration();
            
            // Check for conflicts
            checkForConflicts();
            
            document.getElementById('bookingModal').style.display = 'flex';
        }
        
        // Check for conflicts in real-time
        async function checkForConflicts() {
            const roomId = document.getElementById('booking_room_id').value;
            const startTimestamp = document.getElementById('booking_start_time').value;
            const endTimestamp = document.getElementById('booking_end_time').value;
            
            if (!roomId || !startTimestamp || !endTimestamp || isCheckingConflicts) {
                return;
            }
            
            isCheckingConflicts = true;
            
            try {
                const response = await fetch('check_conflict.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `room_id=${roomId}&start_time=${startTimestamp}&end_time=${endTimestamp}`
                });
                
                const data = await response.json();
                
                const conflictWarning = document.getElementById('conflictWarning');
                const conflictMessage = document.getElementById('conflictMessage');
                const submitBtn = document.getElementById('submitBookingBtn');
                
                if (data.hasConflicts) {
                    let message = 'Time slot conflicts with existing bookings. ';
                    
                    if (data.canAdjust) {
                        message += 'System will automatically adjust times by 2 minutes to avoid conflicts.';
                        submitBtn.innerHTML = '<i class="fas fa-clock"></i> Book with Time Adjustment';
                        submitBtn.style.background = 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)';
                    } else {
                        message += 'Please choose a different time.';
                        submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Cannot Book (Conflict)';
                        submitBtn.disabled = true;
                        submitBtn.style.background = '#ef4444';
                    }
                    
                    conflictMessage.innerHTML = message;
                    conflictWarning.style.display = 'block';
                } else {
                    conflictWarning.style.display = 'none';
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Booking';
                    submitBtn.disabled = false;
                    submitBtn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #1e40af 100%)';
                }
            } catch (error) {
                console.error('Error checking conflicts:', error);
            } finally {
                isCheckingConflicts = false;
            }
        }
        
        // Update dashboard when filters change
        function updateDashboard() {
            const roomId = document.getElementById('room_filter').value;
            const areaId = document.getElementById('area_filter').value;
            const date = '<?php echo $selected_date; ?>';
            
            let url = `dashboard.php?date=${date}`;
            if (areaId > 0) url += `&area=${areaId}`;
            if (roomId > 0) url += `&room=${roomId}`;
            
            window.location.href = url;
        }
        
        // Filter rooms based on selected area
        function filterRooms() {
            const areaId = document.getElementById('area_filter').value;
            const date = '<?php echo $selected_date; ?>';
            
            let url = `dashboard.php?date=${date}`;
            if (areaId > 0) url += `&area=${areaId}`;
            
            window.location.href = url;
        }
        
        // Navigate to previous/next day
        function navigateDate(direction) {
            const currentDate = '<?php echo $selected_date; ?>';
            const roomId = document.getElementById('room_filter').value;
            const areaId = document.getElementById('area_filter').value;
            
            const dateObj = new Date(currentDate);
            if (direction === '+1') {
                dateObj.setDate(dateObj.getDate() + 1);
            } else {
                dateObj.setDate(dateObj.getDate() - 1);
            }
            
            const newDate = dateObj.toISOString().split('T')[0];
            let url = `dashboard.php?date=${newDate}`;
            if (areaId > 0) url += `&area=${areaId}`;
            if (roomId > 0) url += `&room=${roomId}`;
            
            window.location.href = url;
        }
        
        // Go to today
        function goToToday() {
            const today = new Date().toISOString().split('T')[0];
            const roomId = document.getElementById('room_filter').value;
            const areaId = document.getElementById('area_filter').value;
            
            let url = `dashboard.php?date=${today}`;
            if (areaId > 0) url += `&area=${areaId}`;
            if (roomId > 0) url += `&room=${roomId}`;
            
            window.location.href = url;
        }
        
        // Change date using date picker
        function changeDate() {
            const selectedDate = document.getElementById('date_picker').value;
            const roomId = document.getElementById('room_filter').value;
            const areaId = document.getElementById('area_filter').value;
            
            let url = `dashboard.php?date=${selectedDate}`;
            if (areaId > 0) url += `&area=${areaId}`;
            if (roomId > 0) url += `&room=${roomId}`;
            
            window.location.href = url;
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('bookingModal').style.display = 'none';
            document.getElementById('bookingForm').reset();
            document.getElementById('externalFields').style.display = 'none';
            // Reset event type to Internal
            document.querySelector('select[name="event_type"]').value = 'I';
            // Reset submit button
            const submitBtn = document.getElementById('submitBookingBtn');
            submitBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Booking';
            submitBtn.disabled = false;
            submitBtn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #1e40af 100%)';
        }
        
        // Toggle external fields based on event type
        function toggleExternalFields() {
            const eventType = document.querySelector('select[name="event_type"]').value;
            const externalFields = document.getElementById('externalFields');
            const representativeInput = document.getElementById('representative');
            
            if (eventType === 'E') {
                externalFields.style.display = 'block';
                if (representativeInput) representativeInput.required = true;
            } else {
                externalFields.style.display = 'none';
                if (representativeInput) representativeInput.required = false;
            }
        }
        
        // Toggle repeat until date
        function toggleRepeatUntil() {
            const repeatType = document.querySelector('input[name="repeat_type"]:checked').value;
            const repeatUntilSection = document.getElementById('repeatUntilSection');
            
            if (repeatType !== 'none') {
                repeatUntilSection.style.display = 'block';
                
                // Set default repeat until date (30 days from start)
                const startDate = document.getElementById('start_date').value;
                if (startDate) {
                    const start = new Date(startDate);
                    const futureDate = new Date(start);
                    futureDate.setDate(futureDate.getDate() + 30);
                    
                    document.getElementById('repeat_until').value = futureDate.toISOString().split('T')[0];
                }
            } else {
                repeatUntilSection.style.display = 'none';
            }
        }
        
        // Calculate duration
        function calculateDuration() {
            const startDate = document.getElementById('start_date').value;
            const startTime = document.getElementById('start_time_input').value;
            const endDate = document.getElementById('end_date').value;
            const endTime = document.getElementById('end_time_input').value;
            
            if (startDate && startTime && endDate && endTime) {
                const startDateTimeLocal = new Date(startDate + 'T' + startTime);
                const endDateTimeLocal = new Date(endDate + 'T' + endTime);
                
                // Update hidden timestamp fields
                const startTimestamp = Math.floor(startDateTimeLocal.getTime() / 1000);
                const endTimestamp = Math.floor(endDateTimeLocal.getTime() / 1000);
                
                document.getElementById('booking_start_time').value = startTimestamp;
                document.getElementById('booking_end_time').value = endTimestamp;
                
                if (endDateTimeLocal > startDateTimeLocal) {
                    const diffMs = endDateTimeLocal - startDateTimeLocal;
                    const diffHours = diffMs / (1000 * 60 * 60);
                    const diffMinutes = (diffMs % (1000 * 60 * 60)) / (1000 * 60);
                    
                    let durationText = 'Duration: ';
                    if (diffHours >= 1) {
                        durationText += `${diffHours.toFixed(2)} hours`;
                    } else {
                        durationText += `${diffMinutes} minutes`;
                    }
                    
                    document.getElementById('durationText').textContent = durationText;
                } else {
                    document.getElementById('durationText').textContent = 'Duration: Invalid (end before start)';
                }
                
                // Check for conflicts after time change
                setTimeout(checkForConflicts, 500);
            }
        }
        
        // Handle form submission
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Basic validation
            const eventName = this.querySelector('[name="event_name"]').value.trim();
            const briefDesc = this.querySelector('[name="brief_desc"]').value.trim();
            const startDate = this.querySelector('[name="start_date"]').value;
            const startTime = this.querySelector('[name="start_time_input"]').value;
            const endDate = this.querySelector('[name="end_date"]').value;
            const endTime = this.querySelector('[name="end_time_input"]').value;
            const eventType = this.querySelector('[name="event_type"]').value;
            
            if (!eventName) {
                alert('Please enter an event name.');
                return false;
            }
            
            if (!briefDesc) {
                alert('Please enter a brief description.');
                return false;
            }
            
            if (eventType === 'E') {
                const representative = this.querySelector('[name="representative"]').value.trim();
                if (!representative) {
                    alert('Representative name is required for external events.');
                    return false;
                }
                
                // Check if at least one preparation is selected
                const preparations = this.querySelectorAll('input[name^="needs_"]:checked');
                const otherPreparations = this.querySelector('[name="other_preparations"]').value.trim();
                
                if (preparations.length === 0 && !otherPreparations) {
                    alert('Please specify at least one preparation needed for external events.');
                    return false;
                }
            }
            
            // Validate date/time
            const start = new Date(startDate + 'T' + startTime);
            const end = new Date(endDate + 'T' + endTime);
            
            if (end <= start) {
                alert('End date/time must be after start date/time!');
                return false;
            }
            
            // Show confirmation if there are conflicts
            const conflictWarning = document.getElementById('conflictWarning');
            if (conflictWarning.style.display === 'block') {
                const confirmed = confirm(
                    'Warning: This time slot conflicts with existing bookings.\n\n' +
                    'The system will automatically adjust your booking times by 2 minutes to avoid conflicts.\n\n' +
                    'Do you want to proceed?'
                );
                
                if (!confirmed) {
                    return false;
                }
            }
            
            // Submit form
            this.submit();
            return true;
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set min date for date pickers
            const today = new Date().toISOString().split('T')[0];
            const datePicker = document.getElementById('date_picker');
            if (datePicker) {
                datePicker.min = today;
            }
            
            document.getElementById('start_date').min = today;
            document.getElementById('end_date').min = today;
            
            // Initialize external fields
            toggleExternalFields();
            
            // Initialize repeat until
            toggleRepeatUntil();
            
            // Add event listeners for duration calculation and conflict checking
            ['start_date', 'start_time_input', 'end_date', 'end_time_input'].forEach(function(id) {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', calculateDuration);
                }
            });
            
            // Auto-close notifications after 8 seconds
            setTimeout(() => {
                const notifications = document.querySelectorAll('.notification');
                notifications.forEach(notification => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                });
            }, 8000);
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape - close modal
            if (e.key === 'Escape') {
                closeModal();
                closeLoginModal();
            }
        });
    </script>
</body>
</html>