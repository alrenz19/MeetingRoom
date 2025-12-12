<?php
require_once 'connection.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $required_fields = ['event_name', 'brief_desc', 'start_date', 'start_time', 'end_date', 'end_time', 'area_id', 'room_id', 'representative', 'event_type'];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $error_message = "Please fill in all required fields.";
            break;
        }
    }
    
    if (!isset($error_message)) {
        // Get form data
        $event_name = sanitize_input($_POST['event_name']);
        $brief_desc = sanitize_input($_POST['brief_desc']);
        $full_desc = sanitize_input($_POST['full_desc']);
        $start_date = $_POST['start_date'];
        $start_time = $_POST['start_time'];
        $end_date = $_POST['end_date'];
        $end_time = $_POST['end_time'];
        $area_id = (int)$_POST['area_id'];
        $room_id = (int)$_POST['room_id'];
        $representative = sanitize_input($_POST['representative']);
        $event_type = $_POST['event_type']; // 'I' for internal, 'E' for external
        $confirmation_status = $_POST['confirmation_status'];
        $notification_email = filter_var($_POST['notification_email'], FILTER_SANITIZE_EMAIL);
        
        // Prepare needs
        $preparations = [];
        if (isset($_POST['needs_water'])) $preparations[] = 'Water';
        if (isset($_POST['needs_whiteboard'])) $preparations[] = 'Whiteboard';
        if (isset($_POST['needs_coffee'])) $preparations[] = 'Coffee';
        if (isset($_POST['needs_projector'])) $preparations[] = 'Projector';
        if (isset($_POST['needs_snacks'])) $preparations[] = 'Snacks';
        $other_preparations = sanitize_input($_POST['other_preparations']);
        if ($other_preparations) {
            $preparations[] = $other_preparations;
        }
        $preparations_text = implode(', ', $preparations);
        
        // Repeat settings
        $repeat_type = $_POST['repeat_type'];
        $repeat_until = !empty($_POST['repeat_until']) ? $_POST['repeat_until'] : null;
        
        // Calculate timestamps
        $start_datetime = strtotime($start_date . ' ' . $start_time);
        $end_datetime = strtotime($end_date . ' ' . $end_time);
        
        if ($end_datetime <= $start_datetime) {
            $error_message = "End time must be after start time.";
        } else {
            // Calculate duration in hours
            $duration_hours = ($end_datetime - $start_datetime) / 3600;
            
            // Get current user (for demo, using representative)
            $current_user = $representative;
            
            try {
                $pdo->beginTransaction();
                
                if ($repeat_type === 'none') {
                    // Single booking
                    $sql = "INSERT INTO mrbs_entry (
                        start_time, end_time, entry_type, room_id, 
                        create_by, name, type, description, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $pdo->prepare($sql);
                    $status = ($confirmation_status === 'confirmed') ? 0 : 1; // 0=confirmed, 1=tentative?
                    
                    $stmt->execute([
                        $start_datetime,
                        $end_datetime,
                        0, // entry_type (0 for single event)
                        $room_id,
                        $current_user,
                        $event_name,
                        $event_type,
                        $brief_desc . "\n\n" . $full_desc . "\n\nPreparations needed: " . $preparations_text,
                        $status
                    ]);
                    
                    $booking_id = $pdo->lastInsertId();
                    $success_message = "Booking created successfully! Booking ID: $booking_id";
                } else {
                    // Recurring booking
                    $repeat_opt = '0000000'; // Default: no days selected
                    $rep_interval = 1;
                    
                    // Set repeat options based on type
                    switch($repeat_type) {
                        case 'daily':
                            $repeat_opt = '1111111'; // All days
                            $rep_type = 1;
                            break;
                        case 'weekly':
                            $repeat_opt = '0010000'; // Wednesday (for example)
                            $rep_type = 2;
                            break;
                        case 'monthly':
                            $rep_type = 3;
                            break;
                        case 'yearly':
                            $rep_type = 4;
                            break;
                    }
                    
                    $end_date_timestamp = $repeat_until ? strtotime($repeat_until) : $start_datetime;
                    
                    $sql = "INSERT INTO mrbs_repeat (
                        start_time, end_time, rep_type, end_date, rep_opt, room_id,
                        create_by, name, type, description, rep_interval, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $pdo->prepare($sql);
                    $status = ($confirmation_status === 'confirmed') ? 0 : 1;
                    
                    $stmt->execute([
                        $start_datetime,
                        $end_datetime,
                        $rep_type,
                        $end_date_timestamp,
                        $repeat_opt,
                        $room_id,
                        $current_user,
                        $event_name,
                        $event_type,
                        $brief_desc . "\n\n" . $full_desc . "\n\nPreparations needed: " . $preparations_text,
                        $rep_interval,
                        $status
                    ]);
                    
                    $repeat_id = $pdo->lastInsertId();
                    
                    // Also create entry for the first occurrence
                    $sql = "INSERT INTO mrbs_entry (
                        start_time, end_time, entry_type, repeat_id, room_id,
                        create_by, name, type, description, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $start_datetime,
                        $end_datetime,
                        1, // entry_type (1 for repeating)
                        $repeat_id,
                        $room_id,
                        $current_user,
                        $event_name,
                        $event_type,
                        $brief_desc . "\n\n" . $full_desc . "\n\nPreparations needed: " . $preparations_text,
                        $status
                    ]);
                    
                    $success_message = "Recurring booking created successfully!";
                }
                
                // Send notification email if provided
                if ($notification_email) {
                    $subject = "Booking Confirmation: $event_name";
                    $message = "Your booking has been created successfully.\n\n";
                    $message .= "Event: $event_name\n";
                    $message .= "Date: " . date('Y-m-d', $start_datetime) . "\n";
                    $message .= "Time: " . date('H:i', $start_datetime) . " to " . date('H:i', $end_datetime) . "\n";
                    $message .= "Room: " . get_room_name($room_id) . "\n";
                    $message .= "Status: " . ucfirst($confirmation_status) . "\n";
                    $message .= "\nThank you for using our booking system.";
                    
                    mail($notification_email, $subject, $message);
                }
                
                $pdo->commit();
                
            } catch(PDOException $e) {
                $pdo->rollBack();
                $error_message = "Error creating booking: " . $e->getMessage();
            }
        }
    }
}

// Helper function to get room name
function get_room_name($room_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT room_name FROM mrbs_room WHERE id = ?");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch();
    return $room ? $room['room_name'] : 'Unknown Room';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRBS Booking System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .main-content {
            padding: 30px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eef2f7;
        }

        .section-title {
            font-size: 1.3rem;
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 0.95rem;
        }

        .required::after {
            content: " *";
            color: #ef4444;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            font-family: inherit;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .datetime-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .datetime-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .duration-display {
            grid-column: 1 / -1;
            text-align: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            font-weight: 500;
            color: #667eea;
            margin-top: 10px;
        }

        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-option {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .repeat-section {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            margin-top: 10px;
        }

        .repeat-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .repeat-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .repeat-until {
            margin-top: 15px;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #eef2f7;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back {
            background: #f1f5f9;
            color: #64748b;
        }

        .btn-back:hover {
            background: #e2e8f0;
        }

        .btn-save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.2);
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 10px;
            }
            
            .main-content {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .datetime-row {
                grid-template-columns: 1fr;
            }
            
            .datetime-group {
                grid-template-columns: 1fr 1fr;
            }
            
            .checkbox-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div>
                    <h1><i class="fas fa-calendar-plus"></i> MRBS Booking System</h1>
                    <p class="subtitle">Meeting Room Booking System - Create New Booking</p>
                </div>
                <div style="text-align: right; color: rgba(255,255,255,0.9);">
                    <p>Created by: GA Personnel</p>
                </div>
            </div>
        </header>

        <div class="main-content">
            <?php if(isset($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="bookingForm">
                <!-- Basic Information -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-info-circle"></i> Event Information</h2>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="event_name" class="required">Event Name</label>
                            <input type="text" id="event_name" name="event_name" required placeholder="Enter event name">
                        </div>
                        
                        <div class="form-group">
                            <label for="brief_desc" class="required">Brief Description</label>
                            <textarea id="brief_desc" name="brief_desc" rows="2" required placeholder="Short description of the event"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="full_desc">Full Description</label>
                        <textarea id="full_desc" name="full_desc" rows="4" placeholder="Detailed description of the event"></textarea>
                    </div>
                </div>

                <!-- Date & Time -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-clock"></i> Date & Time</h2>
                    
                    <div class="datetime-row">
                        <div>
                            <label class="required">Start Date & Time</label>
                            <div class="datetime-group">
                                <input type="date" id="start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                                <input type="time" id="start_time" name="start_time" required value="13:30">
                            </div>
                        </div>
                        
                        <div>
                            <label class="required">End Date & Time</label>
                            <div class="datetime-group">
                                <input type="date" id="end_date" name="end_date" required value="<?php echo date('Y-m-d'); ?>">
                                <input type="time" id="end_time" name="end_time" required value="20:15">
                            </div>
                        </div>
                    </div>
                    
                    <div class="duration-display">
                        <i class="fas fa-hourglass-half"></i>
                        <span id="durationText">Duration: 6.75 hours</span>
                    </div>
                </div>

                <!-- Location -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-map-marker-alt"></i> Location</h2>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="area_id" class="required">Area</label>
                            <select id="area_id" name="area_id" required onchange="loadRooms()">
                                <option value="">Select Area</option>
                                <?php
                                $areas = get_areas();
                                foreach ($areas as $area): ?>
                                    <option value="<?php echo $area['id']; ?>"><?php echo htmlspecialchars($area['area_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="room_id" class="required">Room</label>
                            <select id="room_id" name="room_id" required>
                                <option value="">Select Area First</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Event Details -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-users"></i> Event Details</h2>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Type</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="event_type" value="I"> Internal
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="event_type" value="E" checked> External
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="representative" class="required">Representative</label>
                            <input type="text" id="representative" name="representative" required placeholder="Enter representative name">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Confirmation Status</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="confirmation_status" value="tentative"> Tentative
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="confirmation_status" value="confirmed" checked> Confirmed
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="notification_email">Send Notification To</label>
                            <input type="email" id="notification_email" name="notification_email" placeholder="email@example.com">
                        </div>
                    </div>
                </div>

                <!-- Things to Prepare -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-clipboard-check"></i> Things to Prepare</h2>
                    
                    <div class="checkbox-grid">
                        <label class="checkbox-option">
                            <input type="checkbox" name="needs_water" value="1"> Water
                        </label>
                        
                        <label class="checkbox-option">
                            <input type="checkbox" name="needs_whiteboard" value="1"> Whiteboard
                        </label>
                        
                        <label class="checkbox-option">
                            <input type="checkbox" name="needs_coffee" value="1"> Coffee
                        </label>
                        
                        <label class="checkbox-option">
                            <input type="checkbox" name="needs_projector" value="1"> Projector
                        </label>
                        
                        <label class="checkbox-option">
                            <input type="checkbox" name="needs_snacks" value="1"> Snacks
                        </label>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <label for="other_preparations">Other Preparations</label>
                        <textarea id="other_preparations" name="other_preparations" rows="2" placeholder="Any other preparation requirements"></textarea>
                    </div>
                </div>

                <!-- Repeat Event -->
                <div class="form-section">
                    <h2 class="section-title"><i class="fas fa-redo"></i> Repeat Event</h2>
                    
                    <div class="repeat-options">
                        <label class="repeat-option">
                            <input type="radio" name="repeat_type" value="none" checked> None
                        </label>
                        
                        <label class="repeat-option">
                            <input type="radio" name="repeat_type" value="daily"> Daily
                        </label>
                        
                        <label class="repeat-option">
                            <input type="radio" name="repeat_type" value="weekly"> Weekly
                        </label>
                        
                        <label class="repeat-option">
                            <input type="radio" name="repeat_type" value="monthly"> Monthly
                        </label>
                        
                        <label class="repeat-option">
                            <input type="radio" name="repeat_type" value="yearly"> Yearly
                        </label>
                    </div>
                    
                    <div class="repeat-until" style="display: none;" id="repeatUntilSection">
                        <label for="repeat_until">Repeat Until</label>
                        <input type="date" id="repeat_until" name="repeat_until">
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="button" class="btn btn-back" onclick="window.history.back()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    
                    <button type="submit" class="btn btn-save">
                        <i class="fas fa-save"></i> Save Booking
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Calculate duration
        function calculateDuration() {
            const startDate = document.getElementById('start_date').value;
            const startTime = document.getElementById('start_time').value;
            const endDate = document.getElementById('end_date').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startDate && startTime && endDate && endTime) {
                const start = new Date(startDate + 'T' + startTime);
                const end = new Date(endDate + 'T' + endTime);
                
                if (end > start) {
                    const diffMs = end - start;
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
            }
        }
        
        // Load rooms based on selected area
        function loadRooms() {
            const areaId = document.getElementById('area_id').value;
            const roomSelect = document.getElementById('room_id');
            
            if (!areaId) {
                roomSelect.innerHTML = '<option value="">Select Area First</option>';
                return;
            }
            
            // AJAX request to get rooms
            const xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        const rooms = JSON.parse(xhr.responseText);
                        roomSelect.innerHTML = '<option value="">Select Room</option>';
                        
                        rooms.forEach(function(room) {
                            const option = document.createElement('option');
                            option.value = room.id;
                            option.textContent = room.room_name + (room.description ? ' - ' + room.description : '');
                            roomSelect.appendChild(option);
                        });
                    } catch (e) {
                        console.error('Error loading rooms:', e);
                        roomSelect.innerHTML = '<option value="">Error loading rooms</option>';
                    }
                }
            };
            
            xhr.open('GET', 'get_rooms.php?area_id=' + areaId, true);
            xhr.send();
        }
        
        // Show/hide repeat until date
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
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Set default end date to today if same as start date
            document.getElementById('end_date').value = '<?php echo date('Y-m-d'); ?>';
            
            // Calculate initial duration
            calculateDuration();
            
            // Add event listeners
            ['start_date', 'start_time', 'end_date', 'end_time'].forEach(function(id) {
                document.getElementById(id).addEventListener('change', calculateDuration);
            });
            
            // Add repeat type change listeners
            document.querySelectorAll('input[name="repeat_type"]').forEach(function(radio) {
                radio.addEventListener('change', toggleRepeatUntil);
            });
            
            // Load default area if exists
            const defaultArea = document.getElementById('area_id').options[1];
            if (defaultArea) {
                defaultArea.selected = true;
                loadRooms();
            }
        });
        
        // Form validation
        document.getElementById('bookingForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('start_date').value;
            const startTime = document.getElementById('start_time').value;
            const endDate = document.getElementById('end_date').value;
            const endTime = document.getElementById('end_time').value;
            
            const start = new Date(startDate + 'T' + startTime);
            const end = new Date(endDate + 'T' + endTime);
            
            if (end <= start) {
                e.preventDefault();
                alert('End date/time must be after start date/time!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>