<?php
// check_conflict.php
date_default_timezone_set('Asia/Manila');
require_once 'connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = (int)$_POST['room_id'];
    $start_time = (int)$_POST['start_time'];
    $end_time = (int)$_POST['end_time'];
    
    // Check for conflicts
    $conflicts = check_booking_conflict_with_buffer($room_id, $start_time, $end_time);
    
    if (!empty($conflicts)) {
        // Try to adjust times
        $adjustment = adjust_booking_times($room_id, $start_time, $end_time);
        
        echo json_encode([
            'hasConflicts' => true,
            'conflicts' => array_map(function($conflict) {
                return [
                    'name' => $conflict['name'],
                    'start_time' => date('g:i A', $conflict['start_time']),
                    'end_time' => date('g:i A', $conflict['end_time'])
                ];
            }, $conflicts),
            'canAdjust' => !empty($adjustment['adjustments']),
            'adjustmentInfo' => $adjustment['adjustments'],
            'adjustedTimes' => [
                'start' => $adjustment['adjusted_start'],
                'end' => $adjustment['adjusted_end'],
                'start_formatted' => date('g:i A', $adjustment['adjusted_start']),
                'end_formatted' => date('g:i A', $adjustment['adjusted_end'])
            ],
            'originalTimes' => [
                'start_formatted' => date('g:i A', $start_time),
                'end_formatted' => date('g:i A', $end_time)
            ]
        ]);
    } else {
        echo json_encode([
            'hasConflicts' => false
        ]);
    }
} else {
    echo json_encode([
        'hasConflicts' => false,
        'error' => 'Invalid request method'
    ]);
}
?>