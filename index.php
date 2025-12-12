<?php
// Create standalone database connection instead of relying on MRBS setup
function create_db_connection() {
    $db_config = [
        'host' => '172.16.81.215', // Change as needed
        'dbname' => 'mrbs', // Your MRBS database name
        'username' => 'mrbsNuser', // Your database username
        'password' => 'MrbsPassword123!' // Your database password
    ];
    
    try {
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $db_config['username'], $db_config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $pdo;
    } catch (\PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Custom query functions to replace MRBS db() functions
function db_query_one($sql, $params = []) {
    $pdo = create_db_connection();
    if (!$pdo) return 0;
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : 0;
    } catch (\PDOException $e) {
        error_log("Query error: " . $e->getMessage());
        return 0;
    }
}

function db_query_all($sql, $params = []) {
    $pdo = create_db_connection();
    if (!$pdo) return [];
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log("Query error: " . $e->getMessage());
        return [];
    }
}

function db_command($sql, $params = []) {
    $pdo = create_db_connection();
    if (!$pdo) return false;
    
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (\PDOException $e) {
        error_log("Command error: " . $e->getMessage());
        return false;
    }
}

// Check if user is logged in (you'll need to implement your own session check)
function is_logged_in() {
    return isset($_SESSION['user_id']); // Adjust based on your session management
}

// Check if user is admin (you'll need to implement your own admin check)
function is_admin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'; // Adjust based on your user management
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pagination settings
$per_page = 10; // Number of meetings per page
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $per_page;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM mrbs_entry AS e 
              WHERE e.entry_type = 0 AND e.type = 'E'
              AND DATE(FROM_UNIXTIME(e.start_time)) >= CURDATE()";

try {
    $total_meetings = db_query_one($count_sql);
    $total_pages = ceil($total_meetings / $per_page);
} catch (Exception $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_meetings = 0;
    $total_pages = 1;
}

// Main query with pagination
$sql = "SELECT 
      e.id AS entry_id,
      creator.display_name AS creator_name,
      r.room_name,
      a.area_name,
      e.name,
      e.description,
      e.create_by,
      e.start_time,
      e.end_time,
      e.type,
      prep.prepare,
      p.participants,
      g.guest_participants

  FROM mrbs_entry AS e

  JOIN mrbs_users AS creator ON creator.name = e.create_by
  JOIN mrbs_room AS r ON e.room_id = r.id
  JOIN mrbs_area AS a ON r.area_id = a.id

  -- Subquery for prepare (optional)
  LEFT JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS prepare
      FROM mrbs_prepare
      GROUP BY entry_id
  ) prep ON prep.entry_id = e.id

  -- Participants
  LEFT JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(DISTINCT u.display_name ORDER BY u.display_name SEPARATOR ', ') AS participants
      FROM mrbs_groups AS mg
      JOIN mrbs_users AS u ON LOWER(TRIM(u.email)) = LOWER(TRIM(mg.email))
      WHERE mg.email IS NOT NULL AND mg.email != ''
      GROUP BY mg.entry_id
  ) p ON p.entry_id = e.id

  -- Guests
  LEFT JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(DISTINCT full_name ORDER BY full_name SEPARATOR ', ') AS guest_participants
      FROM mrbs_groups
      WHERE (email IS NULL OR email = '') AND full_name IS NOT NULL AND full_name != ''
      GROUP BY entry_id
  ) g ON g.entry_id = e.id

  WHERE e.entry_type = 0 AND e.type = 'E'
    AND DATE(FROM_UNIXTIME(e.start_time)) >= CURDATE()

  ORDER BY e.start_time ASC
  LIMIT $per_page OFFSET $offset";

try {
    $res = db_query_all($sql);
    $meetings = [];

    $currentTime = time();

    foreach ($res as $row) {
        $startTime = $row['start_time'];
        $endTime = $row['end_time'];
        
        // Determine meeting status
        $status = 'upcoming';
        if ($currentTime > $endTime) {
            $status = 'done';
        } elseif ($currentTime >= $startTime && $currentTime <= $endTime) {
            $status = 'inprogress';
        }
        
        // Format dates and times
        $startDate = date('M d, Y', $startTime);
        $startTimeFormatted = date('h:i A', $startTime);
        $endTimeFormatted = date('h:i A', $endTime);
        $duration = round(($endTime - $startTime) / 3600, 1) . ' hrs';
        
        // Parse prepare items
        $prepare_items = [];
        if (!empty($row['prepare'])) {
            $prepare_items = array_map('trim', explode(',', $row['prepare']));
        }
        
        $meetings[] = [
            'id' => $row['entry_id'],
            'meeting_title' => $row['name'],
            'description' => $row['description'],
            'organizer' => $row['creator_name'],
            'created_by' => $row['create_by'],
            'room' => $row['room_name'] . ' - ' . $row['area_name'],
            'start_date' => $startDate,
            'start_time' => $startTimeFormatted,
            'end_time' => $endTimeFormatted,
            'duration' => $duration,
            'type' => $row['type'],
            'participants' => $row['participants'] ?? '',
            'guest_participants' => $row['guest_participants'] ?? '',
            'prepare' => $row['prepare'] ?? '',
            'prepare_items' => $prepare_items,
            'status' => $status
        ];
    }
} catch (Exception $e) {
    // Log error but don't crash the page
    error_log("Admin meetings error: " . $e->getMessage());
    $meetings = [];
    $total_meetings = 0;
    $total_pages = 1;
}

// Handle actions - only if user is logged in AND admin
if (isset($_POST['action']) && is_logged_in() && is_admin()) {
    $action = $_POST['action'];
    $meetingId = $_POST['meeting_id'] ?? null;
    
    switch ($action) {
        case 'delete':
            if ($meetingId) {
                try {
                    $delete_sql = "DELETE FROM mrbs_entry WHERE id = ?";
                    db_command($delete_sql, [$meetingId]);
                    // Also delete related records
                    db_command("DELETE FROM mrbs_groups WHERE entry_id = ?", [$meetingId]);
                    db_command("DELETE FROM mrbs_prepare WHERE entry_id = ?", [$meetingId]);
                    
                    // Refresh page
                    header("Location: admin_meetings.php?page=" . $current_page);
                    exit;
                } catch (Exception $e) {
                    error_log("Delete error: " . $e->getMessage());
                }
            }
            break;
    }
}

// Build pagination URL base
$pagination_base = "admin_meetings.php?" . http_build_query(array_merge($_GET, ['page' => 'PAGE']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin - Meeting Management</title>
  <link rel="stylesheet" href="./public/css/tailwind.min.css" />
  <style>
    .status-badge {
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
    }
    .status-upcoming { background-color: #fee2e2; color: #dc2626; }
    .status-inprogress { background-color: #fef3c7; color: #d97706; }
    .status-done { background-color: #dcfce7; color: #16a34a; }
    
    .table-row:hover {
      background-color: #f8fafc;
    }
    
    .badge {
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
    }
    .badge-internal { background-color: #e0e7ff; color: #3730a3; }
    .badge-external { background-color: #fef3c7; color: #92400e; }
    .badge-prepare { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    
    .prepare-item {
      display: inline-block;
      margin: 2px;
    }

    .pagination-link {
      padding: 8px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      text-decoration: none;
      color: #374151;
      transition: all 0.2s;
    }
    .pagination-link:hover {
      background-color: #f3f4f6;
      border-color: #9ca3af;
    }
    .pagination-link.active {
      background-color: #3b82f6;
      color: white;
      border-color: #3b82f6;
    }
    .pagination-link.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    
    /* Notification badge in header */
    .header-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background-color: #dc2626;
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      font-size: 12px;
      font-weight: bold;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .action-disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
  </style>
</head>

<body class="bg-gray-50 font-sans">
  <div class="min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
        <div class="flex items-center">
            <div class="relative">
            <h1 class="text-2xl font-bold text-gray-900">Meeting Management</h1>
            </div>
            <p class="text-gray-600 ml-4">Admin dashboard for managing all meetings</p>
        </div>
        <!-- <div class="flex items-center space-x-4">
            <?php// if (is_logged_in()): ?>
                <span class="text-sm text-gray-600">Welcome, <?php //echo $_SESSION['user_name'] ?? 'User'; ?></span>
                <a href="logout.php" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors">
                Logout
                </a>
            <?php// else: ?>
                <span class="text-sm text-gray-600">Welcome, Guest</span>
                <a href="login.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                Login
                </a>
            <?php //endif; ?>
            <a href="index.php" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
            Back to Main
            </a>
        </div> -->
        </div>
    </div>
    </header>

    <!-- Stats Overview -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="rounded-full bg-blue-100 p-3">
              <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
            </div>
            <div class="ml-4">
              <p class="text-sm font-medium text-gray-600">Total Meetings</p>
              <p class="text-2xl font-semibold text-gray-900"><?php echo $total_meetings; ?></p>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="rounded-full bg-green-100 p-3">
              <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
            </div>
            <div class="ml-4">
              <p class="text-sm font-medium text-gray-600">Upcoming</p>
              <p class="text-2xl font-semibold text-gray-900">
                <?php 
                  $upcoming_count = 0;
                  foreach ($meetings as $meeting) {
                    if ($meeting['status'] === 'upcoming') $upcoming_count++;
                  }
                  echo $upcoming_count;
                ?>
              </p>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="rounded-full bg-yellow-100 p-3">
              <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div class="ml-4">
              <p class="text-sm font-medium text-gray-600">In Progress</p>
              <p class="text-2xl font-semibold text-gray-900">
                <?php 
                  $inprogress_count = 0;
                  foreach ($meetings as $meeting) {
                    if ($meeting['status'] === 'inprogress') $inprogress_count++;
                  }
                  echo $inprogress_count;
                ?>
              </p>
            </div>
          </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="rounded-full bg-gray-100 p-3">
              <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <div class="ml-4">
              <p class="text-sm font-medium text-gray-600">Completed</p>
              <p class="text-2xl font-semibold text-gray-900">
                <?php 
                  $done_count = 0;
                  foreach ($meetings as $meeting) {
                    if ($meeting['status'] === 'done') $done_count++;
                  }
                  echo $done_count;
                ?>
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Meetings Table -->
      <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
          <div class="flex justify-between items-center">
            <div>
              <h2 class="text-lg font-semibold text-gray-800">All Meetings</h2>
              <p class="text-sm text-gray-600">Manage and view all scheduled meetings</p>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="text-sm text-gray-600">
              Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Meeting Details</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time & Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Participants</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Preparation</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php if (empty($meetings)): ?>
                <tr>
                  <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No meetings found</h3>
                    <p class="mt-1 text-sm text-gray-500">Get started by creating a new meeting.</p>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($meetings as $meeting): ?>
                  <tr class="table-row transition-colors duration-150">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="flex items-center">
                        <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-lg flex items-center justify-center">
                          <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                          </svg>
                        </div>
                        <div class="ml-4">
                          <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($meeting['meeting_title']); ?></div>
                          <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($meeting['organizer']); ?></div>
                          <?php if ($meeting['description']): ?>
                            <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(substr($meeting['description'], 0, 100)); ?><?php echo strlen($meeting['description']) > 100 ? '...' : ''; ?></div>
                          <?php endif; ?>
                          <div class="flex gap-2 mt-1">
                            <?php if ($meeting['type'] === 'I'): ?>
                              <span class="badge badge-internal">Internal</span>
                            <?php elseif ($meeting['type'] === 'E'): ?>
                              <span class="badge badge-external">External</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-gray-900"><?php echo $meeting['start_date']; ?></div>
                      <div class="text-sm text-gray-500"><?php echo $meeting['start_time']; ?> - <?php echo $meeting['end_time']; ?></div>
                      <div class="text-xs text-gray-400"><?php echo $meeting['duration']; ?></div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-gray-900"><?php echo htmlspecialchars($meeting['room']); ?></div>
                    </td>
                    <td class="px-6 py-4">
                      <div class="text-sm text-gray-900">
                        <?php if ($meeting['participants']): ?>
                          <div class="mb-1">
                            <span class="font-medium">Internal:</span> 
                            <?php echo htmlspecialchars(substr($meeting['participants'], 0, 50)); ?><?php echo strlen($meeting['participants']) > 50 ? '...' : ''; ?>
                          </div>
                        <?php endif; ?>
                        <?php if ($meeting['guest_participants']): ?>
                          <div>
                            <span class="font-medium">Guests:</span> 
                            <?php echo htmlspecialchars(substr($meeting['guest_participants'], 0, 50)); ?><?php echo strlen($meeting['guest_participants']) > 50 ? '...' : ''; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <div class="text-sm text-gray-900">
                        <?php if (!empty($meeting['prepare_items'])): ?>
                          <div class="flex flex-wrap gap-1">
                            <?php foreach ($meeting['prepare_items'] as $item): ?>
                              <span class="badge badge-prepare prepare-item">
                                <?php echo htmlspecialchars($item); ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <span class="text-gray-400 text-xs">No items</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <span class="status-badge status-<?php echo $meeting['status']; ?>">
                        <?php echo ucfirst($meeting['status']); ?>
                      </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <div class="flex space-x-2">
                        <?php if (is_logged_in() && is_admin()): ?>
                        <!-- Edit Button (Admin Only) -->
                        <a href="edit_entry.php?id=<?php echo $meeting['id']; ?>" 
                            class="text-blue-600 hover:text-blue-900 transition-colors" title="Edit">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        
                        <!-- Delete Button (Admin Only) -->
                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this meeting?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900 transition-colors" title="Delete">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            </button>
                        </form>
                        <?php else: ?>
                        <!-- View Only Mode for Non-Admins/Guests -->
                        <span class="text-gray-400 cursor-not-allowed action-disabled" title="<?php echo is_logged_in() ? 'Edit (Admin Only)' : 'Please login to edit'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </span>
                        <span class="text-gray-400 cursor-not-allowed action-disabled" title="<?php echo is_logged_in() ? 'Delete (Admin Only)' : 'Please login to delete'; ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </span>
                        <?php endif; ?>
                    </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
          <div class="flex items-center justify-between">
            <div class="text-sm text-gray-700">
              Showing <?php echo count($meetings); ?> of <?php echo $total_meetings; ?> meetings
              (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
            </div>
            <div class="flex space-x-2">
              <!-- First Page -->
              <?php if ($current_page > 1): ?>
                <a href="<?php echo str_replace('PAGE', 1, $pagination_base); ?>" class="pagination-link">
                  &laquo; First
                </a>
              <?php else: ?>
                <span class="pagination-link disabled">&laquo; First</span>
              <?php endif; ?>

              <!-- Previous Page -->
              <?php if ($current_page > 1): ?>
                <a href="<?php echo str_replace('PAGE', $current_page - 1, $pagination_base); ?>" class="pagination-link">
                  &lsaquo; Prev
                </a>
              <?php else: ?>
                <span class="pagination-link disabled">&lsaquo; Prev</span>
              <?php endif; ?>

              <!-- Page Numbers -->
              <?php
              $start_page = max(1, $current_page - 2);
              $end_page = min($total_pages, $current_page + 2);
              
              for ($i = $start_page; $i <= $end_page; $i++): 
                if ($i == $current_page): ?>
                  <span class="pagination-link active"><?php echo $i; ?></span>
                <?php else: ?>
                  <a href="<?php echo str_replace('PAGE', $i, $pagination_base); ?>" class="pagination-link">
                    <?php echo $i; ?>
                  </a>
                <?php endif;
              endfor; ?>

              <!-- Next Page -->
              <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo str_replace('PAGE', $current_page + 1, $pagination_base); ?>" class="pagination-link">
                  Next &rsaquo;
                </a>
              <?php else: ?>
                <span class="pagination-link disabled">Next &rsaquo;</span>
              <?php endif; ?>

              <!-- Last Page -->
              <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo str_replace('PAGE', $total_pages, $pagination_base); ?>" class="pagination-link">
                  Last &raquo;
                </a>
              <?php else: ?>
                <span class="pagination-link disabled">Last &raquo;</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php else: ?>
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
          <div class="flex items-center justify-between">
            <div class="text-sm text-gray-700">
              Showing <?php echo count($meetings); ?> of <?php echo $total_meetings; ?> meetings
            </div>
            <div class="text-sm text-gray-700">
              Last updated: <?php echo date('M j, Y g:i A'); ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
    let knownMeetingIds = [];

    // Update browser badge, title, favicon
    function updateBadge(count) {
      // App badge (if supported)
      if ('setAppBadge' in navigator) {
        navigator.setAppBadge(count);
      }

      // Page title
      document.title = count > 0 ? `(${count}) Meeting Management` : 'Meeting Management';

      // Header badge
      const headerBadge = document.getElementById('header-badge');
      if (headerBadge) {
        if (count > 0) {
          headerBadge.textContent = count > 99 ? '99+' : count;
          headerBadge.classList.remove('hidden');
        } else {
          headerBadge.classList.add('hidden');
        }
      }

      // Favicon badge
      updateFaviconBadge(count);
    }

    function updateFaviconBadge(count) {
      const favicon = document.querySelector("link[rel*='icon']") || document.createElement('link');
      favicon.rel = 'icon';
      favicon.type = 'image/png';
      const img = new Image();
      img.onload = function() {
        const canvas = document.createElement('canvas');
        canvas.width = 32;
        canvas.height = 32;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, 32, 32);

        if (count > 0) {
          // Red circle
          ctx.fillStyle = '#dc2626';
          ctx.beginPath();
          ctx.arc(24, 8, 8, 0, 2 * Math.PI);
          ctx.fill();

          // White text
          ctx.fillStyle = 'white';
          ctx.font = 'bold 10px Arial';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillText(count > 99 ? '99+' : count.toString(), 24, 8);
        }

        favicon.href = canvas.toDataURL('image/png');
        document.head.appendChild(favicon);
      };
      img.src = '/mrbs2025/web/images/favicon.ico';
    }

    // Notify user about new meeting
    function notifyNewMeeting(meetingId) {
      fetch(`get_meeting.php?id=${meetingId}`)
        .then(res => res.json())
        .then(meeting => {
          if (Notification.permission === 'granted') {
            new Notification("🆕 New Meeting Added", {
              body: `${meeting.name}\nBy: ${meeting.organizer}`,
              icon: "/mrbs2025/web/images/favicon.ico"
            });
          }
        });
    }

    // Fetch badge count from API
    function fetchBadgeCount() {
      fetch('badge_api.php')
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            updateBadge(data.badge_count);

            // Detect new meetings
            const newIds = data.latest_ids.filter(id => !knownMeetingIds.includes(id));
            newIds.forEach(id => notifyNewMeeting(id));

            // Update known IDs
            knownMeetingIds = data.latest_ids;
          }
        })
        .catch(err => console.error('Badge fetch error:', err));
    }

    // Initial setup
    document.addEventListener('DOMContentLoaded', () => {
      // Request notification permission
      if (Notification.permission !== 'granted' && Notification.permission !== 'denied') {
        Notification.requestPermission();
      }

      fetchBadgeCount();             // initial fetch
      setInterval(fetchBadgeCount, 30000);  // repeat every 30s
    });
  </script>

</body>
</html>