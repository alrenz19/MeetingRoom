<?php
namespace MRBS;

// Standalone database configuration
$db_host = '172.16.81.215';
$db_name = 'mrbs';
$db_user = 'mrbsNuser';
$db_pass = 'MrbsPassword123!';

// Cache settings
$cacheFile = __DIR__ . '/cache/fetching_guest_booking_cache.json';
$cacheTTL = 15; // seconds (adjust as needed)

// Function to get cached data if valid
function getCache($cacheFile, $cacheTTL) {
    if (!file_exists($cacheFile)) return false;

    $fileTime = filemtime($cacheFile);
    if (($fileTime + $cacheTTL) < time()) {
        // Cache expired
        return false;
    }

    $content = file_get_contents($cacheFile);
    if (!$content) return false;

    return json_decode($content, true);
}

// Function to create standalone database connection
function createDatabaseConnection($host, $dbname, $username, $password) {
    try {
        // Create PDO connection
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (\PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
}

// Function to execute SQL query using standalone connection
function executeQuery($pdo, $sql) {
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log("Query failed: " . $e->getMessage());
        return [];
    }
}

// Try to get cached data
$data = getCache($cacheFile, $cacheTTL);

if (!$data) {
    // Cache miss or expired — run the DB query
    $pdo = createDatabaseConnection($db_host, $db_name, $db_user, $db_pass);
    
    $sql = "SELECT 
      e.id AS entry_id,
      creator.display_name AS creator_name,
      r.room_name,
      a.area_name,
      e.name,
      e.description,
      prep.prepare, -- aggregated prepares (nullable if none)
      CASE 
          WHEN DATE(FROM_UNIXTIME(e.start_time)) = CURDATE() 
              THEN CONCAT(
                  DATE_FORMAT(FROM_UNIXTIME(e.start_time), '%h:%i %p'),
                  ' to ',
                  DATE_FORMAT(FROM_UNIXTIME(e.end_time), '%h:%i %p')
              )
          ELSE CONCAT(
                  DATE_FORMAT(FROM_UNIXTIME(e.start_time), '%Y-%m-%d %h:%i %p'),
                  ' to ',
                  DATE_FORMAT(FROM_UNIXTIME(e.end_time), '%h:%i %p')
              )
      END AS reservation_time,
      p.participants,
      g.guest_participants,
      CASE 
          WHEN DATE(FROM_UNIXTIME(e.start_time)) = CURDATE() THEN 'today_reservation'
          ELSE 'upcoming_reservation'
      END AS reservation_group

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
  JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(DISTINCT u.display_name ORDER BY u.display_name SEPARATOR ', ') AS participants
      FROM mrbs_groups AS mg
      JOIN mrbs_users AS u ON LOWER(TRIM(u.email)) = LOWER(TRIM(mg.email))
      WHERE mg.email IS NOT NULL AND mg.email != ''
      GROUP BY mg.entry_id
  ) p ON p.entry_id = e.id

  -- Guests
  JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(DISTINCT full_name ORDER BY full_name SEPARATOR ', ') AS guest_participants
      FROM mrbs_groups
      WHERE (email IS NULL OR email = '') AND full_name IS NOT NULL AND full_name != ''
      GROUP BY entry_id
  ) g ON g.entry_id = e.id

  WHERE e.entry_type = 0 
    AND DATE(FROM_UNIXTIME(e.start_time)) >= CURDATE()

  ORDER BY reservation_group, e.start_time";

    $res = executeQuery($pdo, $sql);

    $today = [];
    $tomorrow = [];
    $doneMeeting = [];
    $currentTime = time();

    foreach ($res as $row) {
        $timeRange = $row['reservation_time'] ?? '';
        $times = preg_split('/\s*to\s*/i', $timeRange);

        if (count($times) !== 2) {
            continue; // Skip invalid format
        }

        $startStr = $times[0];
        $endStr = $times[1];
        $status_reservation = $row['reservation_group'] ?? 'upcoming_reservation';

        if ($status_reservation === 'today_reservation') {
            $todayDate = date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $startStr)) {
                $startStr = $todayDate . ' ' . $startStr;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $endStr)) {
                $endStr = $todayDate . ' ' . $endStr;
            }
        }

        $startTime = strtotime($startStr);
        $endTime = strtotime($endStr);

        $participants = array_filter(array_map('trim', explode(',', $row['participants'] ?? '')));

        $status_meeting = 'upcoming'; // default

        if ($status_reservation === 'today_reservation') {
            if ($currentTime > $endTime) {
                $status_meeting = 'done';
            } elseif ($currentTime >= $startTime && $currentTime <= $endTime) {
                $status_meeting = 'inprogress';
            } else {
                $status_meeting = 'upcoming';
            }
        } else {
            $status_meeting = 'upcoming';
        }

        $dateStr = ($status_reservation !== 'today_reservation') ? date('M d, Y', $startTime) : '';

        $prepare = [];
        if (!empty($row['prepare'])) {
            // If prepare is coming as comma-separated string
            $prepare = array_map('trim', explode(',', $row['prepare']));
        }
        $entry = [
            'guestName' => $row['guest_participants'] ?? '',
            'meetingTitle' => $row['name'] ?? '',
            'description' => $row['description'] ?? '',
            'creator' => $row['creator_name'] ?? '',
            'status' => $status_meeting,
            'date' => $dateStr,
            'time' => $timeRange,
            'prepare' => $prepare,
            'room' => ($row['room_name'] ?? 'No room yet') . '-' . ($row['area_name'] ?? ''),
            'participants' => array_values($participants),
        ];

        if ($status_meeting === 'done' && $status_reservation === 'today_reservation') {
            $doneMeeting[] = $entry;
        } elseif ($status_reservation === 'today_reservation' && $status_meeting !== 'done') {
            $today[] = $entry;
        } else {
            $tomorrow[] = $entry;
        }
    }

    $data = [
        'today' => $today,
        'tomorrow' => $tomorrow,
        'done' => $doneMeeting,
    ];

    // Save to cache file as JSON
    file_put_contents($cacheFile, json_encode($data));
}

// Calculate ETag based on cached data (or fresh data)
$etag = '"' . md5(json_encode($data)) . '"';

// Send ETag header
header("ETag: $etag");

// Check for If-None-Match header to respond 304 if match
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    // Data not modified
    http_response_code(304);
    exit;
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($data);
exit;