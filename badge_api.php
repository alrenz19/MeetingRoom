<?php
namespace MRBS;
require 'admin_meetings_helpers.php';

// --- Main logic ---
try {
    // Count upcoming meetings for badge
    $badge_sql = "SELECT COUNT(*) as badge_count 
                  FROM mrbs_entry AS e
                  WHERE e.entry_type = 0 AND e.type = 'E'
                  AND DATE(FROM_UNIXTIME(start_time)) >= CURDATE()
                  AND start_time > UNIX_TIMESTAMP()";

    $badge_count = db_query_one($badge_sql);

    // Count meetings created in the last 24 hours as "new"
    $new_meetings_sql = "SELECT COUNT(*) as new_count 
                         FROM mrbs_entry AS e
                         WHERE e.entry_type = 0 AND e.type = 'E' 
                         AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";

    $new_count = db_query_one($new_meetings_sql);

    $total_badge_count = max($badge_count, $new_count);

    echo json_encode([
        'badge_count' => $total_badge_count,
        'last_updated' => date('Y-m-d H:i:s'),
        'status' => 'success'
    ]);

} catch (\Exception $e) {
    echo json_encode([
        'badge_count' => 0,
        'last_updated' => date('Y-m-d H:i:s'),
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
