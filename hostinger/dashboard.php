<?php
define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$appName     = setting('app_name',    APP_NAME);
$socketUrl   = setting('socket_url',  SOCKET_URL);
$socketToken = setting('node_api_key', NODE_API_KEY);
$darkMode    = setting('dark_mode',   true);
$notifSound  = setting('notification_sound', true);
$timezone    = setting('timezone',    APP_TIMEZONE);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($appName) ?> — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="loading">

<!-- ============================================================
     3-COLUMN APP LAYOUT
     ============================================================ -->
<div id="app-layout">

  <!-- ══════════════════════════════════════════════════════════
       LEFT SIDEBAR
       ══════════════════════════════════════════════════════════ -->
  <aside id="sidebar">

    <!-- Logo -->
    <div class="sidebar-header">
      <a href="/dashboard.php" class="sidebar-logo">
        <div class="sidebar-logo-icon">⚡</div>
        <div>
          <div class="sidebar-logo-text"><?= htmlspecialchars($appName) ?></div>
          <div class="sidebar-logo-sub">Outreach OS</div>
        </div>
      </a>
    </div>

    <div class="sidebar-body">

      <!-- WhatsApp Status -->
      <div class="wa-status-badge">
        <div class="wa-status-dot disconnected" id="wa-status-dot"></div>
        <div class="wa-status-info">
          <div class="wa-status-label" id="wa-status-label">Disconnected</div>
          <div class="wa-status-sub" id="wa-status-sub">Not connected</div>
        </div>
        <button id="btn-qr-scan" class="icon-btn" title="Scan QR / Connect WhatsApp" style="flex-shrink:0">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM17 17h3v3h-3zM14 17v3"/></svg>
        </button>
      </div>

      <!-- KPI Cards -->
      <div class="nav-section-label">Overview</div>
      <div class="kpi-grid">
        <div class="kpi-card kpi-indigo">
          <div class="kpi-value" id="kpi-total-leads">—</div>
          <div class="kpi-label">Total Leads</div>
        </div>
        <div class="kpi-card kpi-emerald">
          <div class="kpi-value" id="kpi-valid-wa">—</div>
          <div class="kpi-label">Valid WA</div>
        </div>
        <div class="kpi-card kpi-violet">
          <div class="kpi-value" id="kpi-sent">—</div>
          <div class="kpi-label">Sent</div>
        </div>
        <div class="kpi-card kpi-amber">
          <div class="kpi-value" id="kpi-replied">—</div>
          <div class="kpi-label">Replied</div>
        </div>
      </div>

      <!-- Today Stats -->
      <div style="display:flex;gap:6px;margin:4px 0">
        <div style="flex:1;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px">
          <div style="font-size:16px;font-weight:700;color:var(--text-primary)" id="kpi-sent-today">—</div>
          <div style="font-size:10px;color:var(--text-muted)">Sent today</div>
        </div>
        <div style="flex:1;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px">
          <div style="font-size:16px;font-weight:700;color:var(--emerald)" id="kpi-replied-today">—</div>
          <div style="font-size:10px;color:var(--text-muted)">Replied today</div>
        </div>
      </div>

      <!-- Reply Rate -->
      <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;margin:4px 0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
          <span style="font-size:10px;color:var(--text-muted)">Reply Rate</span>
          <span style="font-size:12px;font-weight:700;color:var(--indigo-light)" id="kpi-reply-rate">—</span>
        </div>
        <div class="progress-bar"><div class="progress-fill" id="kpi-reply-progress" style="width:0%"></div></div>
      </div>

      <!-- Navigation -->
      <div class="nav-section-label">Navigation</div>
      <a class="nav-item active" href="/dashboard.php">
        <svg class="nav-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
        Dashboard
        <span class="nav-badge hidden" id="nav-unread-badge">0</span>
      </a>
      <a class="nav-item" href="/settings.php">
        <svg class="nav-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Settings
      </a>
      <a class="nav-item" href="/logout.php">
        <svg class="nav-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        Sign Out
      </a>

      <!-- Campaign Controls -->
      <div class="nav-section-label" style="margin-top:8px">Campaign</div>
      <div class="queue-bar">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:11px;color:var(--text-muted)">Queue</span>
          <span style="font-size:11px;font-weight:600;color:var(--text-primary)"><span id="queue-length">0</span> jobs</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:2px">
          <span style="font-size:10px;color:var(--text-muted)" id="queue-status-label">Idle</span>
          <span style="font-size:10px;color:var(--text-muted)"><span id="queue-send-count">0</span>/<span id="queue-daily-limit">50</span> today</span>
        </div>
        <div class="queue-progress-track" style="margin-top:6px">
          <div class="queue-progress-fill" id="queue-progress-fill" style="width:0%"></div>
        </div>
      </div>

    </div><!-- /sidebar-body -->

    <!-- Campaign action buttons -->
    <div class="campaign-controls">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">
        <button id="btn-validate-batch" class="btn btn-secondary btn-sm" title="Validate WhatsApp numbers">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          Validate
        </button>
        <button id="btn-generate-batch" class="btn btn-secondary btn-sm" title="Generate AI messages">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          AI Generate
        </button>
      </div>
      <button id="btn-start-campaign" class="btn-campaign start">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Start Outreach
      </button>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
        <button id="btn-pause-campaign" class="btn-campaign pause" style="font-size:11px;padding:6px">⏸ Pause</button>
        <button id="btn-stop-campaign"  class="btn-campaign stop"  style="font-size:11px;padding:6px">⏹ Stop</button>
      </div>
    </div>

    <!-- Socket status -->
    <div class="socket-status">
      <div class="socket-dot disconnected" id="socket-dot"></div>
      <span id="socket-label">Connecting…</span>
      <button id="btn-sync" class="icon-btn" style="margin-left:auto" title="Sync now">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
      </button>
    </div>

  </aside><!-- /sidebar -->


  <!-- ══════════════════════════════════════════════════════════
       MIDDLE COLUMN — Lead List
       ══════════════════════════════════════════════════════════ -->
  <div id="middle-col">

    <!-- Header: Search + Filters -->
    <div class="middle-header">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <div class="search-box" style="flex:1">
          <svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input type="text" id="search-leads" class="search-input" placeholder="Search leads, phone, city…" autocomplete="off">
        </div>

        <!-- CSV Upload button -->
        <div style="position:relative">
          <button class="btn btn-secondary btn-sm" onclick="document.getElementById('csv-file-input').click()" title="Import CSV">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Import
          </button>
          <input type="file" id="csv-file-input" accept=".csv,.txt" style="display:none">
        </div>
      </div>

      <!-- Upload progress -->
      <div id="upload-progress" style="display:none;margin-bottom:8px">
        <div class="progress-bar"><div class="progress-fill" id="upload-fill" style="width:0%"></div></div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:3px">Importing leads…</div>
      </div>

      <!-- Filter pills row 1: WA status -->
      <div class="filter-pills">
        <span style="font-size:10px;color:var(--text-disabled);line-height:22px;flex-shrink:0">WA:</span>
        <button class="filter-pill" data-filter="wa_status" data-value="valid">Valid</button>
        <button class="filter-pill" data-filter="wa_status" data-value="pending">Pending</button>
        <button class="filter-pill" data-filter="wa_status" data-value="not_on_whatsapp">No WA</button>
        <button class="filter-pill" data-filter="wa_status" data-value="invalid">Invalid</button>
      </div>

      <!-- Filter pills row 2: Outreach status -->
      <div class="filter-pills" style="margin-top:5px">
        <span style="font-size:10px;color:var(--text-disabled);line-height:22px;flex-shrink:0">Status:</span>
        <button class="filter-pill" data-filter="outreach_status" data-value="pending">Pending</button>
        <button class="filter-pill" data-filter="outreach_status" data-value="sent">Sent</button>
        <button class="filter-pill" data-filter="outreach_status" data-value="replied">Replied</button>
        <button class="filter-pill" data-filter="outreach_status" data-value="failed">Failed</button>
      </div>

      <!-- Filter pills row 3: Type -->
      <div class="filter-pills" style="margin-top:5px">
        <span style="font-size:10px;color:var(--text-disabled);line-height:22px;flex-shrink:0">Type:</span>
        <button class="filter-pill" data-filter="website_status" data-value="has_website">Has Website</button>
        <button class="filter-pill" data-filter="website_status" data-value="no_website">No Website</button>
        <button class="filter-pill" data-filter="pitch_type" data-value="type_a">Type A</button>
        <button class="filter-pill" data-filter="pitch_type" data-value="type_b">Type B</button>
      </div>

      <!-- Lead count -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
        <span style="font-size:11px;color:var(--text-muted)" id="leads-count-label">Loading…</span>
        <button id="btn-mark-all-read" class="btn btn-ghost btn-sm" onclick="fetch('/api/mark_read.php',{method:'POST',body:JSON.stringify({action:'mark_all_read'}),headers:{'Content-Type':'application/json'}}).then(()=>Toast.info('All marked read'))" style="font-size:10px">Mark all read</button>
      </div>
    </div>

    <!-- Lead list (scrollable) -->
    <div id="lead-list" class="lead-list">
      <!-- Lead items rendered by dashboard.js -->
    </div>

    <!-- Load more -->
    <div style="padding:12px;display:none" id="load-more-leads">
      <button class="btn btn-secondary" style="width:100%;font-size:12px">Load more leads</button>
    </div>

  </div><!-- /middle-col -->


  <!-- ══════════════════════════════════════════════════════════
       RIGHT COLUMN — Chat + Lead Details
       ══════════════════════════════════════════════════════════ -->
  <div id="right-col">

    <!-- Empty state (shown when no lead selected) -->
    <div id="right-empty-state" class="empty-state" style="height:100%">
      <div class="empty-icon">💬</div>
      <div class="empty-title">Select a lead to view conversation</div>
      <div class="empty-sub">Choose a lead from the middle panel to see their messages and outreach history</div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('csv-file-input').click()">
          Import CSV
        </button>
        <button class="btn btn-secondary btn-sm" id="btn-validate-batch-right">Validate Numbers</button>
      </div>
    </div>

    <!-- Chat view (shown when lead selected) -->
    <div id="right-chat" style="display:none;flex-direction:column;height:100%">

      <!-- Chat Header -->
      <div class="chat-header">
        <div class="chat-avatar" id="chat-avatar">?</div>
        <div class="chat-header-info">
          <div class="chat-title" id="chat-title">Select a lead</div>
          <div class="chat-subtitle" id="chat-subtitle"></div>
        </div>
        <div style="display:flex;align-items:center;gap:4px">
          <span id="header-wa-badge"></span>
          <span id="header-outreach-badge"></span>
        </div>
        <div class="chat-header-actions">
          <button id="btn-lead-details" class="btn btn-secondary btn-sm" title="View lead details">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Details
          </button>
          <button id="btn-restart-wa" class="icon-btn" title="Restart WhatsApp engine">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
          </button>
        </div>
      </div>

      <!-- Replied notice -->
      <div id="reply-notice" style="display:none;padding:8px 20px;background:rgba(16,185,129,0.08);border-bottom:1px solid rgba(16,185,129,0.15)">
        <div style="font-size:12px;color:var(--emerald-light);display:flex;align-items:center;gap:6px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Lead has replied — automation stopped. Continue conversation manually below.
        </div>
      </div>

      <!-- AI message banner -->
      <div id="ai-message-banner" class="ai-message-banner" style="display:none">
        <div class="ai-message-banner-label">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
          AI Outreach Message Ready
        </div>
        <div class="ai-message-text" id="ai-message-preview">Personalized message will be sent when campaign runs.</div>
      </div>

      <!-- Messages area -->
      <div id="chat-messages" class="chat-messages">
        <!-- Messages rendered by dashboard.js -->
      </div>

      <!-- Typing indicator (hidden by default) -->
      <div id="typing-indicator" style="display:none;padding:4px 20px">
        <div style="display:flex;gap:4px;align-items:center">
          <div style="width:6px;height:6px;border-radius:50%;background:var(--text-muted);animation:pulse-dot 1s ease-in-out infinite"></div>
          <div style="width:6px;height:6px;border-radius:50%;background:var(--text-muted);animation:pulse-dot 1s ease-in-out infinite 0.2s"></div>
          <div style="width:6px;height:6px;border-radius:50%;background:var(--text-muted);animation:pulse-dot 1s ease-in-out infinite 0.4s"></div>
        </div>
      </div>

      <!-- Chat input -->
      <div class="chat-input-area">
        <div id="phone-display" style="font-size:10px;color:var(--text-muted);margin-bottom:6px" id="chat-phone-display"></div>
        <div class="chat-input-box">
          <textarea
            id="chat-textarea"
            class="chat-textarea"
            rows="1"
            placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
            disabled
          ></textarea>
          <button id="send-message-btn" class="btn-send" title="Send message" disabled>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </div>
        <div style="font-size:10px;color:var(--text-disabled);margin-top:4px">
          Manual messages bypass the queue and send immediately
        </div>
      </div>

    </div><!-- /right-chat -->
  </div><!-- /right-col -->

</div><!-- /app-layout -->

<!-- Toast container -->
<div id="toast-container"></div>

<!-- ============================================================
     JAVASCRIPT — Load order matters
     ============================================================ -->
<!-- Socket.io CDN -->
<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>

<!-- App scripts -->
<script src="/assets/js/ui-components.js"></script>
<script src="/assets/js/socket-handler.js"></script>
<script src="/assets/js/dashboard.js"></script>
<script src="/assets/js/app.js"></script>

<!-- Global CRM config (injected from PHP) -->
<script>
window.CRM = {
  config: {
    socketUrl:   '<?= htmlspecialchars($socketUrl) ?>',
    socketToken: '<?= htmlspecialchars($socketToken) ?>',
    appName:     '<?= htmlspecialchars($appName) ?>',
    timezone:    '<?= htmlspecialchars($timezone) ?>',
  },
  settings: {
    darkMode:          <?= $darkMode ? 'true' : 'false' ?>,
    notificationSound: <?= $notifSound ? 'true' : 'false' ?>,
  },
  activeLead: null,
  version:    '<?= APP_VERSION ?>',
};

// Show right chat panel when lead selected, hide empty state
window.addEventListener('DOMContentLoaded', () => {
  const origSelectLead = Dashboard.selectLead;
  // Proxy selectLead to toggle panels
  const _origInit = Dashboard.init;

  // Wire right panel visibility
  document.addEventListener('click', (e) => {
    const item = e.target.closest('[data-lead-id]');
    if (item) {
      document.getElementById('right-empty-state').style.display = 'none';
      const chat = document.getElementById('right-chat');
      if (chat) chat.style.display = 'flex';
    }
  });

  // Re-wire validate batch button on right panel empty state
  document.getElementById('btn-validate-batch-right')?.addEventListener('click', () => {
    Dashboard.validateBatch();
  });

  // Update reply-rate progress bar when KPIs load
  const origLoadStats = Dashboard.loadStats;
  Dashboard.loadStats = async function() {
    await origLoadStats.call(this);
    const state = Dashboard.getState();
    const rate = parseFloat(state.stats?.leads?.reply_rate || 0);
    const bar = document.getElementById('kpi-reply-progress');
    if (bar) bar.style.width = Math.min(100, rate) + '%';
  };
});
</script>

</body>
</html>
