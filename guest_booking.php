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
    /* Set zoom to 70% for all pages */
    body {
        zoom: 0.9 !important;
    }

    /* Alternative using transform */
    body {
        transform: scale(0.9) !important;
        transform-origin: 0 0 !important;
        display: flex;
        margin-left: 100px;
    } 

    /* Split view layout */
    .split-container {
      display: flex;
      gap: 20px;
      margin-top: 20px;
    }
    
    .split-column {
      flex: 1;
      min-width: 0; /* Prevents flex items from overflowing */
    }
    
    .split-column h2 {
      margin-bottom: 10px;
    }
    
    /* Carousel container with dynamic height and smooth transition */
    .carousel-container {
      position: relative;
      overflow: hidden;
      transition: height 0.6s ease;
      min-height: 280px; /* Reduced minimum height */
    }

    /* Slide track */
    .slide {
      position: absolute;
      inset: 0;
      transform: translateX(100%); /* start off-screen right */
      transition: transform 0.6s ease-in-out;
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 8px;
      opacity: 1; /* always visible but off-screen */
    }
    /* Fade slides stacked absolutely */
    .fade {
      position: absolute;
      inset: 0; /* top:0; right:0; bottom:0; left:0; */
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.8s ease-in-out;
      display: grid;
      grid-template-columns: repeat(1, minmax(0, 1fr));
      gap: 8px; /* 0.5rem */
    }

    .fade.active {
      opacity: 1;
      pointer-events: auto;
      position: relative; /* bring active slide in flow */
    }

    /* Reduced card height and text sizes */
    .meeting-card {
      width: 100% !important;
      max-width: 100%;
      height: 280px !important; /* Reduced from 390px */
      display: flex;
      border-radius: 15px; /* Slightly smaller radius */
      padding-right: 20px; /* Reduced padding */
      overflow: hidden;
      border: 1px solid #cdcdcd;
      box-shadow: 0 2px 8px rgb(0 0 0 / 0.1);
      background: white;
      margin: auto;
    }

    /* Left side image with overlay for text - reduced */
    .meeting-card .left-side {
      position: relative;
      width: 400px; /* Reduced from 577px */
      height: 280px; /* Reduced from 393px */
      flex-shrink: 0;
      background: url('images/rectangle-2.svg') no-repeat center/cover;
      border-top-left-radius: 15px;
      border-bottom-left-radius: 15px;
      color: white;
      padding: 15px 20px; /* Further reduced padding */
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      user-select: none;
      padding-right: 15px; /* Further reduced padding */
    }

      .meeting-card .left-side.done-column {
        background: url('images/done_svg.png') no-repeat center/cover;
        color: black;
    }
    .meeting-card .guest-name {
      font-size: 1.5rem; /* Further reduced from 2rem */
      margin-bottom: 0.75rem; /* Further reduced spacing */
      flex-shrink: 0;

      /* Allow wrapping - no truncation */
      white-space: normal;
      overflow: visible;
      text-overflow: unset;
    }
    .meeting-card .meeting-title {
      font-size: 18px; /* Further reduced from 22px */
      margin-bottom: 8px; /* Further reduced spacing */
      flex-shrink: 0;
    }
    .meeting-card .description {
      font-size: 14px; /* Further reduced from 18px */
      line-height: 1.2; /* Tighter line height */
      margin-bottom: 15px; /* Further reduced spacing */
      flex-grow: 1;
      overflow-wrap: break-word;
    }
    .meeting-card .creator {
      font-size: 14px; /* Further reduced from 18px */
      font-style: italic;
      opacity: 0.99;
      flex-shrink: 0;
    }

    /* Right side white background with details - reduced */
    .meeting-card .right-side {
      position: relative;
      padding: 40px 12px 12px 12px; /* Further reduced padding */
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      color: #000000ff;
      user-select: none;
    }

    /* Status badges - reduced */
    .status-badge {
      position: absolute;
      top: 10px; /* Further reduced from 12px */
      right: 10px; /* Further reduced from 12px */
      padding: 2px 12px; /* Further reduced padding */
      border-radius: 9999px;
      color: white;
      font-size: 14px; /* Further reduced from 18px */
      user-select: none;
      margin-bottom: 10;
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

    /* Info rows with icon and text - reduced */
    .info-row {
      display: flex;
      align-items: center;
      gap: 8px; /* Further reduced gap */
      font-size: 16px; /* Further reduced from 22px */
      margin-bottom: 8px; /* Further reduced spacing */
    }
    .info-row svg {
      flex-shrink: 0;
      width: 18px; /* Further reduced from 24px */
      height: 18px; /* Further reduced from 24px */
      stroke: #6b7280;
      stroke-width: 1.5;
    }

    /* 2x grid layout - adjusted for smaller cards */
    @media screen and (min-width: 1840px) and (max-width: 2729px) {
      .fade.custom-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: 280px; /* Reduced from 397px */
      }

      .fade.active.custom-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .meeting-card {
        margin: auto;
      }
    }

    /* Large screen overrides - adjusted for smaller cards */
    @media (min-width: 2730px) {
      .fade.custom-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        grid-auto-rows: 280px; /* Reduced from 397px */
      }
      .fade.active.custom-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
      .meeting-card {
        margin: 0;
      }
      .meeting-card .left-side {
        padding: 10px 10px; /* Further reduced padding */
        padding-right: 15px; /* Further reduced padding */
      }
      .meeting-card .guest-name {
        font-size: 24px; /* Further reduced from 32px */
        margin-bottom: 3px; /* Further reduced spacing */
      }
      .meeting-card .meeting-title {
        font-size: 24px; /* Further reduced from 32px */
        margin-bottom: 3px; /* Further reduced spacing */
      }
      .meeting-card .description {
        font-size: 16px; /* Further reduced from 22px */
        margin-bottom: 20px; /* Further reduced from 32px */
      }
      .meeting-card .creator {
        font-size: 14px; /* Further reduced from 18px */
      }
      .info-row {
        font-size: 16px; /* Further reduced from 20px */
        margin-bottom: 12px; /* Further reduced from 16px */
      }
      .info-row svg {
        width: 16px; /* Further reduced from 20px */
        height: 16px; /* Further reduced from 20px */
      }
    }

    /* Week container horizontal layout */
    #weeks-container {
      display: flex;
      gap: 12px; /* 0.75rem */
      justify-content: center;
      flex-wrap: nowrap;
      margin-bottom: 16px; /* 1rem */
    }

    .highlighted {
      background-color: #1976D2;
      color: white;
      padding: 8px 16px;
      border-radius: 6px;
      cursor: pointer;
      user-select: none;
      transition: background-color 0.3s ease;
    }

    .week-item {
      padding: 8px 16px;
      border-radius: 6px;
      cursor: pointer;
      user-select: none;
      background-color: #e5e7eb;
      color: #374151;
      transition: background-color 0.3s ease;
    }

    .week-item:hover:not(.highlighted) {
      background-color: #d1d5db;
    }

    #weeks-container .week-item {
      padding: 0.75rem 1.5rem; /* comfortable padding */
      border-radius: 25px;    /* your custom radius */
      background-color: #e5e7eb;
      cursor: pointer;
      user-select: none;
      transition: background-color 0.3s ease;
    }

    #weeks-container .week-item:hover {
      background-color: #d1d5db;
    }

    #weeks-container .highlighted {
      background-color: #1976D2;
      color: white;
      border-radius: 25px; 
    }

    /* custom.css */
    .max-w-\[3780px\] {
      max-width: 3780px;
    }

    .min-h-\[410px\] {
      min-height: 320px; /* Reduced from 410px */
    }

    .section-label {
      margin-bottom: 16px;
      margin-top: 32px;
    }

    .logo-container {
      max-width: 3780px;
      margin: 0 auto;
    }

  .max-w-300px { max-width: 300px; }
  .max-w-400px { max-width: 400px; }
  .max-w-600px { max-width: 600px; }
  .max-w-800px { max-width: 800px; }
  .max-w-1000px { max-width: 1000px; }

  /* Media queries for responsiveness */

  @media (min-width: 640px) { /* sm */
    .sm\:max-w-400px { max-width: 400px; }
  }

  @media (min-width: 768px) { /* md */
    .md\:max-w-600px { max-width: 600px; }
  }

  @media (min-width: 1024px) { /* lg */
    .lg\:max-w-800px { max-width: 800px; }
  }

  @media (min-width: 1280px) { /* xl */
    .xl\:max-w-1000px { max-width: 1000px; }
  }

  /* Reduced header height */
  .header-container {
    padding: 5px 0;
    height: auto;
    min-height: 80px;
  }

  /* Make logo height consistent */
  .header-container img {
    max-height: 60px;
    object-fit: contain;
  }

  /* Responsive text sizing for smaller header */
  @media (max-width: 640px) {
    .header-container #date {
      font-size: 18px;
    }
    .header-container #clock {
      font-size: 20px;
    }
    .header-container img {
      max-height: 50px;
    }
  }

  @media (max-width: 768px) {
    .split-container {
      flex-direction: column;
    }
  }

  /* Smaller section headings */
  .section-heading {
    font-size: 20px !important; /* Reduced from 4xl/5xl/7xl */
  }

  @media (min-width: 640px) {
    .section-heading {
      font-size: 20px !important;
    }
  }

  @media (min-width: 1024px) {
    .section-heading {
      font-size: 30px !important;
    }
  }

  </style>
</head>

<body class="bg-gray-100 font-sans">

  <div class=" max-w-[1024px] mx-auto p-4">
      <!-- Header with reduced height and inline layout -->
      <div class="bg-white shadow-md header-container">
        <div class="mx-auto p-2 flex items-center justify-between">
          
          <!-- Logo -->
          <img
            src="images/logo.svg"
            alt="Company Logo"
            class="w-auto h-16 max-w-600px sm:max-w-550px md:max-w-400px lg:max-w-450px xl:max-w-600px"
          />
          
          <!-- Date and Time - Inline layout -->
          <div class="flex items-center gap-4 text-right">
            <div>
              <p id="clock" class="text-2xl sm:text-3xl font-semibold text-black">--:--:--</p>
            </div>
          </div>
        </div>
      </div>

    <!-- Split View Section -->
    <div class="split-container">
      <!-- Today Column -->
      <div class="split-column">
        <div class="flex justify-between">
        <h2 class="section-heading font-semibold">Today</h2>
        <!-- Date and Time - Inline layout -->
        <div class="flex items-center gap-4 text-right">
          <div>
            <p id="date" class="text-xl sm:text-2xl mb-[10px] font-light text-black">Loading date...</p>
          </div>
        </div>
        </div>
        <div class="relative min-h-[320] section-label">
          <div id="today-carousel" class="carousel-container relative"></div>
        </div>
      </div>

      <!-- Done Column -->
      <div class="split-column">
        <h2 class="section-heading font-semibold">Done</h2>
        <div class="relative min-h-[320] section-label">
          <div id="done-carousel" class="carousel-container relative"></div>
        </div>
      </div>
    </div>

  </div>



<script>
document.addEventListener('DOMContentLoaded', () => {
  const dateEl = document.getElementById('date');
  const clockEl = document.getElementById('clock');

  function updateDate() {
    const now = new Date();
    const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
    if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', options);
  }

  function updateClock() {
    const now = new Date();
    if (clockEl) clockEl.textContent = now.toLocaleTimeString([], { 
      hour: '2-digit', 
      minute: '2-digit', 
      second: '2-digit'  
    });
  }

  // Update immediately
  updateDate();
  updateClock();

  // Keep clock ticking
  setInterval(updateClock, 1000);




   // --- Optional: Week selector code (if you want, or remove) ---

  const weeksContainer = document.getElementById('weeks-container');
  if (!weeksContainer) return;

  // Get current year & month
  const today = new Date();
  const year = today.getFullYear();
  const month = today.getMonth(); // 0 = January

  // First and last day of month
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);

  // Calculate number of weeks in the month
  const startDayIndex = firstDay.getDay(); // Sunday=0
  const totalDays = lastDay.getDate();
  const totalWeeks = Math.ceil((totalDays + startDayIndex) / 7);

  // Generate weeks list automatically
  const weeks = Array.from({ length: totalWeeks }, (_, i) => `Week ${i + 1}`);

  // Determine current week
  const weekOfMonth = Math.ceil((today.getDate() + startDayIndex) / 7);
  let highlightedWeek = `Week ${weekOfMonth}`;

  function renderWeeks() {
    weeksContainer.innerHTML = '';
    weeks.forEach(week => {
      const div = document.createElement('div');
      div.textContent = week;
      div.className = (week === highlightedWeek) ? 'highlighted' : 'week-item';
      div.onclick = () => {
        highlightedWeek = week;
        renderWeeks();
      };
      weeksContainer.appendChild(div);
    });
  }

  renderWeeks();
});
</script>


<script>
document.addEventListener('DOMContentLoaded', () => {
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
  const maxDescriptionLength = 70;
  const fullNameLength = 20;

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
  prepare,
}) {
  let statusText = '';
  let statusClass = '';
  
  // Determine left side class based on status
  const leftSideClass = status === 'done' ? 'left-side done-column' : 'left-side';
  
  if(status === 'upcoming') {
    statusText = 'Upcoming';
    statusClass = 'status-badge status-upcoming';
  } else if(status === 'inprogress') {
    statusText = 'In Progress';
    statusClass = 'status-badge status-inprogress';
  } else if(status === 'done') {
    statusText = 'Complete';
    statusClass = 'status-badge status-done';
  }

  return `
    <article class="meeting-card">
      <div class="${leftSideClass}">
        <div class="guest-name">${truncateText(guestName, fullNameLength)}</div>
        <div class="meeting-title">${meetingTitle}</div>
        <div class="description">
          <span>Things to prepare: </span> <br/>
            ${
              prepare && prepare.length > 0 
                ? prepare.map(item =>`
                    <label class="desc-item">
                      <input type="checkbox" value="${item}"> ${item}
                    </label>
                  `).join('')
                : `<span class="no-items">No items listed</span>`
            }
        </div>
        <div class="creator">Organizer: ${creator}</div>
      </div>
      <div class="right-side">
        <div class="${statusClass}">${statusText}</div>
        ${date ? `<div class="info-row">${icons.date}<span>${date}</span></div>` : ''}
        <div class="info-row">${icons.time}<span>${time}</span></div>
        <div class="info-row">${icons.room}<span>${room}</span></div>
        <div class="info-row">${icons.participants}<span>${formatParticipants(participants)}</span></div>
      </div>
    </article>
  `;
}
  // --- Participants formatting based on screen width ---
  function formatParticipants(participants) {
    if (!Array.isArray(participants)) return '';
    const width = window.innerWidth;
    let displayCount;

    if (width >= 1840 && width <= 2729) {
      displayCount = 2;
    } else if (width > 2729) {
      displayCount = 4;
    } else {
      displayCount = participants.length;
    }

    if (participants.length > displayCount) {
      const shown = participants.slice(0, displayCount).join(', ');
      const remaining = participants.length - displayCount;
      return `${shown} +${remaining}`;
    }

    return participants.join(', ');
  }

  // --- Carousel layout helpers - updated for smaller cards ---
  function getColumnsCount() {
    const width = window.innerWidth;
    if (width >= 2730) return 3;
    if (width >= 1840) return 2;
    return 1;
  }

  function getRowsCount(container) {
    if (!container) return 0;

    if (container.id === 'done-carousel') {
      return 1;
    }

    const width = window.innerWidth;
    if (width >= 2730 && container.id === 'today-carousel') {
      return 2;
    }
    if (width >= 2730) return 3;
    return 2;
  }

  function getCardsPerPage(container) {
    return getColumnsCount() * getRowsCount(container);
  }

  function calculateHeight(cardsCount, container) {
    const columns = getColumnsCount();
    const fixedRows = getRowsCount(container);
    const rows = Math.min(Math.ceil(cardsCount / columns), fixedRows);

    const rowHeight = 280; // Reduced from 397px
    const gap = 16; // px gap between rows

    return rows * rowHeight + (rows - 1) * gap;
  }

  // --- Chunk array helper ---
  function chunkArray(arr, n) {
    if (!Array.isArray(arr)) {
      console.error('chunkArray expects an array but got:', arr);
      return [];
    }
    const chunks = [];
    for(let i = 0; i < arr.length; i += n) {
      chunks.push(arr.slice(i, i + n));
    }
    return chunks;
  }

  // --- Carousel render function ---
  function renderCarousel(container, meetings) {
    if (!container) return;

    if (!Array.isArray(meetings)) {
      console.error('renderCarousel expected an array for meetings');
      meetings = []; // fallback to empty array
    }

    container.meetingsData = meetings;
    if (meetings.length === 0) {
      // Show "No cards yet" placeholder
      container.innerHTML = `
        <div class="flex items-center justify-center h-64 border-2 border-dashed border-gray-600 p-4">
          <p class="text-8xl">No reservations yet</p>
        </div>
      `;
      container.style.height = '100px'; // set a fixed height for the placeholder
      return;
    }
    const cardsPerPage = getCardsPerPage(container);
    const pages = chunkArray(meetings, cardsPerPage);
    if (pages.length === 0) {
      container.style.height = '0px';
      return; // no pages, nothing to render
    }
    container.innerHTML = '';

    pages.forEach((pageData, idx) => {
      const slide = document.createElement('section');
      slide.className = 'fade custom-grid';
      if (idx === 0) slide.classList.add('active');

      pageData.forEach(data => {
        const tempTemplate = document.createElement('template');
        tempTemplate.innerHTML = meetingCardHTML(data).trim();
        slide.appendChild(tempTemplate.content.firstElementChild);
      });

      container.appendChild(slide);
    });

    if (pages.length <= 1) {
      container.style.height = calculateHeight(pages[0].length, container) + 'px';
      return;
    }

    let currentIndex = 0;
    container.style.height = calculateHeight(pages[0].length, container) + 'px';

    if (container.carouselIntervalId) clearInterval(container.carouselIntervalId);

    container.carouselIntervalId = setInterval(() => {
      const slides = container.querySelectorAll('.fade');
      slides[currentIndex].classList.remove('active');
      const nextIndex = (currentIndex + 1) % slides.length;
      slides[nextIndex].classList.add('active');

      const currentCardsCount = pages[currentIndex].length;
      const nextCardsCount = pages[nextIndex].length;

      const currentHeight = calculateHeight(currentCardsCount, container);
      const nextHeight = calculateHeight(nextCardsCount, container);

      // Always set bigger height first
      const biggerHeight = Math.max(currentHeight, nextHeight);
      container.style.height = biggerHeight + 'px';

      // If shrinking, do it slightly after slide transition
      if (nextHeight < currentHeight) {
        setTimeout(() => {
          container.style.height = nextHeight + 'px';
        }, 300); // match CSS transition
      } else {
        container.style.height = nextHeight + 'px';
      }

      currentIndex = nextIndex;
    }, 25000);

  }

  // --- PHP injected data with fallback to empty arrays ---
  let doneMeetingsData = <?php echo json_encode($doneMeeting ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  let tomorrowMeetingsData = <?php echo json_encode($tomorrow ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  let todayMeetingsData = <?php echo json_encode($today ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

  // --- Container references ---
  const todayContainer = document.getElementById('today-carousel');
  const tomorrowContainer = document.getElementById('tomorrow-carousel');
  const doneContainer = document.getElementById('done-carousel');

  // --- Initial render ---
  renderCarousel(todayContainer, todayMeetingsData);
  renderCarousel(tomorrowContainer, tomorrowMeetingsData);
  renderCarousel(doneContainer, doneMeetingsData);

  // --- Adjust height on resize ---
  window.addEventListener('resize', () => {
    [todayContainer, tomorrowContainer, doneContainer].forEach(container => {
      if (!container) return;
      const activeSlide = container.querySelector('.fade.active');
      if (!activeSlide) return;
      const cardsCount = activeSlide.children.length;
      container.style.height = calculateHeight(cardsCount, container) + 'px';
    });
  });

  // --- Auto-refresh with fetch ---
  let currentData = { today: [], tomorrow: [], done: [] };
  let lastETag = null;
  let fetchInProgress = false;

  function isSameData(newData, oldData) {
    return JSON.stringify(newData) === JSON.stringify(oldData);
  }

  function fetchMeetings() {
    if (fetchInProgress) return;
    fetchInProgress = true;

    fetch('fetching_guest_booking.php', { headers: lastETag ? { 'If-None-Match': lastETag } : {} })
      .then(res => {
        if (res.status === 304) return null;
        lastETag = res.headers.get('ETag');
        return res.json();
      })
      .then(data => {
        if (!data) return;

        if (!isSameData(data.today, currentData.today)) {
          renderCarousel(todayContainer, data.today);
          currentData.today = data.today;
        }
        if (!isSameData(data.tomorrow, currentData.tomorrow)) {
          renderCarousel(tomorrowContainer, data.tomorrow);
          currentData.tomorrow = data.tomorrow;
        }
        if (!isSameData(data.done, currentData.done)) {
          renderCarousel(doneContainer, data.done);
          currentData.done = data.done;
        }
      })
      .catch(err => {
        console.error('Error fetching meetings:', err);
      })
      .finally(() => { fetchInProgress = false; });
  }

  // --- Start polling ---
  fetchMeetings();
  setInterval(fetchMeetings, 5000);

});
</script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const enableFullscreen = () => {
    const el = document.documentElement;

    if (el.requestFullscreen) {
      el.requestFullscreen();
    } else if (el.webkitRequestFullscreen) { // Safari
      el.webkitRequestFullscreen();
    } else if (el.mozRequestFullScreen) { // Firefox
      el.mozRequestFullScreen();
    } else if (el.msRequestFullscreen) { // IE/Edge
      el.msRequestFullscreen();
    }

    // remove the listener so it only runs once
    document.removeEventListener("click", enableFullscreen);
    document.removeEventListener("keydown", enableFullscreen);
  };

  // trigger fullscreen on the first click OR key press
  document.addEventListener("click", enableFullscreen);
  document.addEventListener("keydown", enableFullscreen);
});
</script>

</body>
</html>