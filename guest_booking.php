<?php
// Standalone database configuration - UPDATE THESE WITH YOUR ACTUAL CREDENTIALS
$db_host = '172.16.81.215';
$db_name = 'mrbs'; // Change to your database name
$db_user = 'mrbsNuser'; // Change to your database username
$db_pass = 'MrbsPassword123!'; // Change to your database password

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Modified SQL query to include all types of bookings
$sql = "SELECT 
      e.id AS entry_id,
      COALESCE(creator.display_name, e.create_by) AS creator_name,
      r.room_name,
      a.area_name,
      e.name,
      e.description,
      prep.prepare,
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
                  DATE_FORMAT(FROM_UNIXTIME(e.end_time), '%Y-%m-%d %h:%i %p')
              )
      END AS reservation_time,
      p.participants,
      g.guest_participants,
      CASE 
          WHEN DATE(FROM_UNIXTIME(e.start_time)) = CURDATE() THEN 'today_reservation'
          ELSE 'upcoming_reservation'
      END AS reservation_group

  FROM mrbs_entry AS e

  LEFT JOIN mrbs_users AS creator ON creator.name = e.create_by
  JOIN mrbs_room AS r ON e.room_id = r.id
  JOIN mrbs_area AS a ON r.area_id = a.id

  -- Subquery for prepare items
  LEFT JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(name ORDER BY name SEPARATOR ', ') AS prepare
      FROM mrbs_prepare
      GROUP BY entry_id
  ) prep ON prep.entry_id = e.id

  -- Internal participants (users)
  LEFT JOIN (
      SELECT 
          mg.entry_id,
          GROUP_CONCAT(DISTINCT COALESCE(u.display_name, mg.email) ORDER BY COALESCE(u.display_name, mg.email) SEPARATOR ', ') AS participants
      FROM mrbs_groups AS mg
      LEFT JOIN mrbs_users AS u ON LOWER(TRIM(u.email)) = LOWER(TRIM(mg.email))
      WHERE mg.email IS NOT NULL AND mg.email != ''
      GROUP BY mg.entry_id
  ) p ON p.entry_id = e.id

  -- Guest participants (external guests with names)
  LEFT JOIN (
      SELECT 
          entry_id,
          GROUP_CONCAT(DISTINCT full_name ORDER BY full_name SEPARATOR ', ') AS guest_participants
      FROM mrbs_groups
      WHERE (email IS NULL OR email = '') AND full_name IS NOT NULL AND full_name != ''
      GROUP BY entry_id
  ) g ON g.entry_id = e.id

  WHERE e.entry_type = 0  AND e.type = 'E'
    AND DATE(FROM_UNIXTIME(e.start_time)) >= CURDATE()
    AND (p.participants IS NOT NULL OR g.guest_participants IS NOT NULL OR e.create_by IS NOT NULL)

  ORDER BY e.start_time ASC, reservation_group";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

$today = [];
$tomorrow = [];
$doneMeeting = [];

$currentTime = time();

foreach ($res as $row) {
    $timeRange = $row['reservation_time'] ?? '';
    $times = preg_split('/\s*to\s*/i', $timeRange);

    if (count($times) !== 2) {
        continue;
    }

    $startStr = trim($times[0]);
    $endStr = trim($times[1]);
    $status_reservation = $row['reservation_group'] ?? 'upcoming_reservation';

    // Build full datetime strings if missing date for today_reservation
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

    if ($startTime === false || $endTime === false) {
        continue; // Skip if time parsing fails
    }

    // Combine all participant types
    $internalParticipants = !empty($row['participants']) ? array_filter(array_map('trim', explode(',', $row['participants']))) : [];
    $guestParticipants = !empty($row['guest_participants']) ? array_filter(array_map('trim', explode(',', $row['guest_participants']))) : [];
    
    // Merge all participants
    $allParticipants = array_merge($internalParticipants, $guestParticipants);
    $allParticipants = array_unique(array_filter($allParticipants));

    // Determine meeting status
    $status_meeting = 'upcoming';

    if ($status_reservation === 'today_reservation') {
        if ($currentTime > $endTime) {
            $status_meeting = 'done';
        } elseif ($currentTime >= $startTime && $currentTime <= $endTime) {
            $status_meeting = 'inprogress';
        } else {
            $status_meeting = 'upcoming';
        }
    }

    $dateStr = ($status_reservation !== 'today_reservation') ? date('M d, Y', $startTime) : '';

    $prepare = [];
    if (!empty($row['prepare'])) {
        $prepare = array_map('trim', explode(',', $row['prepare']));
    }

    // Determine display name - prioritize guest participants, then internal, then creator
    $displayGuestName = 'Meeting';
    if (!empty($row['guest_participants'])) {
        $displayGuestName = $row['guest_participants'];
    } elseif (!empty($allParticipants)) {
        $displayGuestName = implode(', ', array_slice($allParticipants, 0, 2));
    } elseif (!empty($row['creator_name'])) {
        $displayGuestName = $row['creator_name'] . "'s Meeting";
    }

    $entry = [
        'guestName' => $displayGuestName,
        'meetingTitle' => $row['name'] ?? 'No Title',
        'description' => $row['description'] ?? 'No description',
        'creator' => $row['creator_name'] ?? 'Unknown',
        'status' => $status_meeting,
        'date' => $dateStr,
        'time' => $timeRange,
        'room' => ($row['room_name'] ?? 'No room') . ' - ' . ($row['area_name'] ?? 'No area'),
        'prepare' => $prepare,
        'participants' => array_values($allParticipants),
    ];

    if ($status_meeting === 'done' && $status_reservation === 'today_reservation') {
        $doneMeeting[] = $entry;
    } elseif ($status_reservation === 'today_reservation' && $status_meeting !== 'done') {
        $today[] = $entry;
    } else {
        $tomorrow[] = $entry;
    }
}

// Debug output (comment this out in production)
// echo "<!-- Debug: Total records: " . count($res) . " -->";
// echo "<!-- Debug: Today: " . count($today) . " -->";
// echo "<!-- Debug: Tomorrow: " . count($tomorrow) . " -->";
// echo "<!-- Debug: Done: " . count($doneMeeting) . " -->";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Meeting Schedule</title>
  <link rel="stylesheet" href="./public/css/tailwind.min.css" />
  <style>
    /* Reset zoom/transform and fix body layout */
    body {
        zoom: 1 !important;
        transform: none !important;
        margin: 0 !important;
        padding: 0 !important;
        display: block !important;
        background-color: #f3f4f6;
        font-family: sans-serif;
    }

    /* Main container */
    .container-wrapper {
        max-width: 100%;
        margin: 0 auto;
        padding: 1rem;
        background-color: #f3f4f6;
    }

    /* Header fixes */
    .header-container {
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        border-radius: 0.75rem;
        margin-bottom: 1.5rem;
        padding: 0.75rem 1.5rem;
    }

    .header-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .logo-container {
        flex-shrink: 0;
    }

    .logo-container img {
        max-height: 60px;
        width: auto;
        object-fit: contain;
    }

    .time-section {
        text-align: right;
        min-width: 200px;
    }

    #clock {
        font-size: 2rem;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.25rem;
    }

    #date {
        font-size: 1.25rem;
        font-weight: 300;
        color: #4b5563;
    }

    /* Split view layout */
    .split-container {
        display: flex;
        gap: 1.5rem;
        margin-top: 1rem;
    }
    
    .split-column {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
    }
    
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding: 0.5rem 0;
    }
    
    .section-heading {
        font-size: 1.75rem !important;
        font-weight: 600;
        color: #1f2937;
        margin: 0 !important;
    }
    
    .today-date {
        font-size: 1.25rem;
        font-weight: 300;
        color: #4b5563;
    }

    /* SIMPLE CONTAINER FOR VERTICAL STACKING */
    .cards-container {
        background: white;
        border-radius: 0.75rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        min-height: 320px;
        width: 100%;
        padding: 1rem;
        overflow-y: auto;
        max-height: calc(100vh - 250px);
    }

    /* Card styles - VERTICAL STACKING */
    .meeting-card {
        display: flex;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        background: white;
        height: 280px;
        width: 100%;
        margin-bottom: 1rem; /* SPACE BETWEEN CARDS */
    }

    /* Remove margin from last card */
    .meeting-card:last-child {
        margin-bottom: 0;
    }

    .meeting-card .left-side {
        position: relative;
        width: 40%;
        flex-shrink: 0;
        background: linear-gradient(135deg, #1976D2 0%, #0D47A1 100%);
        color: white;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .meeting-card .left-side.done-column {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
    }

    .meeting-card .guest-name {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        line-height: 1.3;
        word-break: break-word;
    }

    .meeting-card .meeting-title {
        font-size: 1.125rem;
        font-weight: 500;
        margin-bottom: 0.75rem;
        opacity: 0.95;
    }

    .meeting-card .description {
        font-size: 0.875rem;
        line-height: 1.4;
        margin-bottom: 1rem;
        flex-grow: 1;
        overflow-y: auto;
    }

    .meeting-card .description .desc-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.25rem;
        font-size: 0.875rem;
    }

    .meeting-card .creator {
        font-size: 0.875rem;
        opacity: 0.9;
        font-style: italic;
        margin-top: auto;
    }

    .meeting-card .right-side {
        position: relative;
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        color: #1f2937;
    }

    /* Status badges */
    .status-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        color: white;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.025em;
    }

    .status-upcoming {
        background-color: #dc2626;
    }

    .status-inprogress {
        background-color: #ca8a04;
    }

    .status-done {
        background-color: #16a34a;
    }

    /* Info rows */
    .info-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.875rem;
        margin-bottom: 0.75rem;
        color: #4b5563;
    }

    .info-row svg {
        flex-shrink: 0;
        width: 1rem;
        height: 1rem;
        color: #6b7280;
    }

    /* Empty state */
    .empty-state {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 320px;
        color: #6b7280;
        font-size: 1.25rem;
        text-align: center;
        padding: 2rem;
        border: 2px dashed #d1d5db;
        border-radius: 0.75rem;
        background: #f9fafb;
        width: 100%;
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .split-container {
            flex-direction: column;
            gap: 2rem;
        }
        
        .meeting-card {
            height: auto;
            min-height: 280px;
        }
        
        .meeting-card .left-side {
            width: 35%;
        }
    }

    @media (max-width: 768px) {
        .header-content {
            flex-direction: column;
            text-align: center;
            gap: 1rem;
        }
        
        .time-section {
            text-align: center;
        }
        
        .meeting-card {
            flex-direction: column;
            height: auto;
            min-height: 400px;
        }
        
        .meeting-card .left-side {
            width: 100%;
            min-height: 180px;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }
        
        .section-heading {
            font-size: 1.5rem !important;
        }
        
        .today-date {
            font-size: 1rem;
        }
    }

    /* Fullscreen button */
    .fullscreen-btn {
        position: fixed;
        bottom: 1rem;
        right: 1rem;
        background: #1976D2;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        cursor: pointer;
        z-index: 1000;
        font-size: 0.875rem;
    }

    .fullscreen-btn:hover {
        background: #1565C0;
    }

    /* Scrollbar styling */
    .cards-container::-webkit-scrollbar {
        width: 6px;
    }

    .cards-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 3px;
    }

    .cards-container::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 3px;
    }

    .cards-container::-webkit-scrollbar-thumb:hover {
        background: #555;
    }
  </style>
</head>

<body>
  <div class="container-wrapper">
    <!-- Header -->
    <div class="header-container">
      <div class="header-content">
        <div class="logo-container">
          <img src="images/logo.svg" alt="Company Logo" />
        </div>
        <div class="time-section">
          <p id="clock" class="text-3xl font-semibold text-gray-800">--:--:--</p>
          <p id="date" class="text-xl font-light text-gray-600">Loading date...</p>
        </div>
      </div>
    </div>

    <!-- Split View Section -->
    <div class="split-container">
      <!-- Today Column -->
      <div class="split-column">
        <div class="section-header">
          <h2 class="section-heading">Today</h2>
          <div id="today-date" class="today-date"><?php echo date('M d, Y'); ?></div>
        </div>
        <div id="today-container" class="cards-container"></div>
      </div>

      <!-- Done Column -->
      <div class="split-column">
        <div class="section-header">
          <h2 class="section-heading">Done</h2>
        </div>
        <div id="done-container" class="cards-container"></div>
      </div>
    </div>

    <!-- Fullscreen Button -->
    <button class="fullscreen-btn" onclick="toggleFullscreen()">
      Enter Fullscreen
    </button>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const dateEl = document.getElementById('date');
    const clockEl = document.getElementById('clock');
    const todayDateEl = document.getElementById('today-date');

    function updateDate() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', options);
      
      // Update today's date in Today section header
      if (todayDateEl) {
        todayDateEl.textContent = now.toLocaleDateString('en-US', { 
          month: 'short', 
          day: 'numeric', 
          year: 'numeric' 
        });
      }
    }

    function updateClock() {
      const now = new Date();
      if (clockEl) {
        clockEl.textContent = now.toLocaleTimeString([], { 
          hour: '2-digit', 
          minute: '2-digit', 
          second: '2-digit',
          hour12: true 
        });
      }
    }

    // Update immediately
    updateDate();
    updateClock();

    // Keep clock ticking
    setInterval(updateClock, 1000);

    // --- Icon SVGs ---
    const icons = {
      date: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect width="18" height="16" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
      time: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="9"/><polyline points="12 6 12 12 16 14"/></svg>`,
      room: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><rect x="3" y="7" width="18" height="10" rx="2" ry="2"/><line x1="16" y1="7" x2="16" y2="17"/><line x1="8" y1="7" x2="8" y2="17"/></svg>`,
      participants: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="9" cy="7" r="4"/><circle cx="17" cy="7" r="4"/><path d="M1 21v-2a4 4 0 014-4h4a4 4 0 014 4v2"/></svg>`
    };

    // --- Text truncation helpers ---
    function truncateText(text, maxLength) {
      if (!text) return '';
      if (text.length <= maxLength) return text;
      return text.slice(0, maxLength - 3) + '...';
    }

    // --- Meeting card HTML template ---
    function meetingCardHTML({
      guestName,
      meetingTitle = "Meeting Title",
      description,
      creator = "Creator Name",
      status,
      date,
      time,
      room,
      participants = [],
      prepare = [],
    }) {
      let statusText = '';
      let statusClass = '';
      let leftSideClass = status === 'done' ? 'left-side done-column' : 'left-side';
      
      switch(status) {
        case 'upcoming':
          statusText = 'Upcoming';
          statusClass = 'status-badge status-upcoming';
          break;
        case 'inprogress':
          statusText = 'In Progress';
          statusClass = 'status-badge status-inprogress';
          break;
        case 'done':
          statusText = 'Complete';
          statusClass = 'status-badge status-done';
          break;
        default:
          statusText = 'Unknown';
          statusClass = 'status-badge status-upcoming';
      }

      return `
        <article class="meeting-card">
          <div class="${leftSideClass}">
            <div class="guest-name">${truncateText(guestName, 40)}</div>
            <div class="meeting-title">${truncateText(meetingTitle, 50)}</div>
            <div class="description">
              <strong>Things to prepare:</strong><br/>
              ${prepare.length > 0 
                ? prepare.map(item => `
                    <div class="desc-item">
                      <input type="checkbox"> ${truncateText(item, 30)}
                    </div>
                  `).join('')
                : '<span>No items listed</span>'
              }
            </div>
            <div class="creator">Organizer: ${truncateText(creator, 25)}</div>
          </div>
          <div class="right-side">
            <div class="${statusClass}">${statusText}</div>
            ${date ? `<div class="info-row">${icons.date}<span>${date}</span></div>` : ''}
            <div class="info-row">${icons.time}<span>${time}</span></div>
            <div class="info-row">${icons.room}<span>${truncateText(room, 40)}</span></div>
            <div class="info-row">${icons.participants}<span>${formatParticipants(participants)}</span></div>
          </div>
        </article>
      `;
    }

    // --- Participants formatting ---
    function formatParticipants(participants) {
      if (!Array.isArray(participants) || participants.length === 0) {
        return 'No participants';
      }
      
      const width = window.innerWidth;
      let displayCount;
      
      if (width >= 1920) {
        displayCount = 4;
      } else if (width >= 1400) {
        displayCount = 3;
      } else {
        displayCount = 2;
      }
      
      if (participants.length > displayCount) {
        const shown = participants.slice(0, displayCount).join(', ');
        return `${shown} +${participants.length - displayCount} more`;
      }
      
      return participants.join(', ');
    }

    // --- Render VERTICAL STACK of cards ---
    function renderVerticalCards(container, meetings) {
      if (!container) return;
      
      if (!Array.isArray(meetings) || meetings.length === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <div>
              <p class="text-lg font-medium mb-2">No reservations</p>
              <p class="text-gray-500">There are no meetings scheduled</p>
            </div>
          </div>
        `;
        container.meetingsData = [];
        return;
      }
      
      // Create document fragment for better performance
      const fragment = document.createDocumentFragment();
      
      // Add all cards in VERTICAL order
      meetings.forEach(data => {
        const cardWrapper = document.createElement('div');
        cardWrapper.innerHTML = meetingCardHTML(data);
        fragment.appendChild(cardWrapper.firstElementChild);
      });
      
      // Clear and add new cards
      container.innerHTML = '';
      container.appendChild(fragment);
      
      // Store data for refresh
      container.meetingsData = meetings;
    }

    // --- Data from PHP ---
    const doneMeetingsData = <?php echo json_encode($doneMeeting ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const todayMeetingsData = <?php echo json_encode($today ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    // --- Initial render with VERTICAL STACKING ---
    const todayContainer = document.getElementById('today-container');
    const doneContainer = document.getElementById('done-container');
    
    renderVerticalCards(todayContainer, todayMeetingsData);
    renderVerticalCards(doneContainer, doneMeetingsData);

    // --- Auto-refresh data ---
    let isFetching = false;
    async function refreshData() {
      if (isFetching) return;
      
      try {
        isFetching = true;
        const response = await fetch('fetching_guest_booking.php', {
          headers: {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          }
        });
        
        if (response.ok) {
          const data = await response.json();
          if (data.today) {
            renderVerticalCards(todayContainer, data.today);
          }
          if (data.done) {
            renderVerticalCards(doneContainer, data.done);
          }
        }
      } catch (error) {
        console.error('Error refreshing data:', error);
      } finally {
        isFetching = false;
      }
    }
    
    // Refresh every 30 seconds
    setInterval(refreshData, 30000);
  });

  // Fullscreen functionality
  function toggleFullscreen() {
    const elem = document.documentElement;
    
    if (!document.fullscreenElement) {
      if (elem.requestFullscreen) {
        elem.requestFullscreen();
      } else if (elem.webkitRequestFullscreen) {
        elem.webkitRequestFullscreen();
      } else if (elem.mozRequestFullScreen) {
        elem.mozRequestFullScreen();
      } else if (elem.msRequestFullscreen) {
        elem.msRequestFullscreen();
      }
      document.querySelector('.fullscreen-btn').textContent = 'Exit Fullscreen';
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
      } else if (document.mozCancelFullScreen) {
        document.mozCancelFullScreen();
      } else if (document.msExitFullscreen) {
        document.msExitFullscreen();
      }
      document.querySelector('.fullscreen-btn').textContent = 'Enter Fullscreen';
    }
  }

  // Listen for fullscreen changes
  document.addEventListener('fullscreenchange', updateFullscreenButton);
  document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
  document.addEventListener('mozfullscreenchange', updateFullscreenButton);
  document.addEventListener('MSFullscreenChange', updateFullscreenButton);
  
  function updateFullscreenButton() {
    const btn = document.querySelector('.fullscreen-btn');
    if (btn) {
      btn.textContent = document.fullscreenElement ? 'Exit Fullscreen' : 'Enter Fullscreen';
    }
  }
  </script>
</body>
</html>