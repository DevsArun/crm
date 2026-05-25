<?php
define('CRM_APP', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
requireAuth();
$appName = setting('app_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings — <?= htmlspecialchars($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>
    body { overflow: auto; height: auto; min-height: 100vh; background: var(--bg-base); }
    .settings-layout { display: grid; grid-template-columns: 220px 1fr; min-height: 100vh; }
    .settings-sidebar { background: var(--bg-surface); border-right: 1px solid var(--border); padding: 20px 12px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
    .settings-main { padding: 32px 40px; max-width: 800px; }
    .settings-tab { display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: var(--radius-sm); color: var(--text-secondary); font-size: 13px; font-weight: 500; cursor: pointer; transition: all var(--transition); margin-bottom: 2px; border: none; background: none; width: 100%; text-align: left; }
    .settings-tab:hover { background: var(--bg-hover); color: var(--text-primary); }
    .settings-tab.active { background: var(--bg-active); color: var(--indigo-light); }
    .settings-panel { display: none; }
    .settings-panel.active { display: block; }
    .section-title { font-size: 18px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.3px; margin-bottom: 4px; }
    .section-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; }
    .status-check { padding: 8px 12px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-md); font-size: 12px; margin-bottom: 6px; }
    table { width: 100%; border-collapse: collapse; }
    th { padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--border); }
    td { padding: 8px 12px; }
  </style>
</head>
<body id="settings-page">


<div class="settings-layout">

  <!-- LEFT NAV -->
  <div class="settings-sidebar">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid var(--border)">
      <div style="width:28px;height:28px;background:linear-gradient(135deg,var(--indigo),var(--violet));border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px">⚡</div>
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary)"><?= htmlspecialchars($appName) ?></div>
        <div style="font-size:10px;color:var(--text-muted)">Settings</div>
      </div>
    </div>

    <a href="/dashboard.php" class="settings-tab" style="margin-bottom:12px;color:var(--indigo-light)">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
      Back to Dashboard
    </a>

    <div style="font-size:10px;font-weight:600;color:var(--text-disabled);letter-spacing:0.08em;text-transform:uppercase;padding:8px 8px 4px">Configuration</div>
    <button class="settings-tab active" data-settings-tab="api">🔑 API Keys</button>
    <button class="settings-tab" data-settings-tab="whatsapp">📱 WhatsApp</button>
    <button class="settings-tab" data-settings-tab="campaign">🚀 Campaign</button>
    <button class="settings-tab" data-settings-tab="profile">👤 Seller Profile</button>

    <div style="font-size:10px;font-weight:600;color:var(--text-disabled);letter-spacing:0.08em;text-transform:uppercase;padding:16px 8px 4px">System</div>
    <button class="settings-tab" data-settings-tab="security">🔒 Security</button>
    <button class="settings-tab" data-settings-tab="features">⚙ Features</button>
    <button class="settings-tab" data-settings-tab="logs">📋 System Logs</button>

    <div style="margin-top:auto;padding-top:20px;border-top:1px solid var(--border);margin-top:24px">
      <div id="wa-session-status-bar" style="font-size:11px;color:var(--text-muted);margin-bottom:8px">
        WA: <span id="wa-session-status">—</span> &nbsp;|&nbsp; <span id="wa-session-phone">—</span>
      </div>
      <a href="/logout.php" class="btn btn-danger btn-sm" style="width:100%;justify-content:center">Sign Out</a>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div class="settings-main" id="settings-content">

    <!-- ── SYSTEM STATUS ───────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:28px">
      <div class="status-check" id="status-groq">Groq: checking…</div>
      <div class="status-check" id="status-node">Node URL: checking…</div>
      <div class="status-check" id="status-nodekey">Node Key: checking…</div>
      <div class="status-check" id="status-webhook">Webhook: checking…</div>
      <div class="status-check" id="status-pass">Password: checking…</div>
      <div class="status-check" id="status-online">Node Server: checking…</div>
    </div>

    <!-- ── API KEYS PANEL ──────────────────────────────────── -->
    <div id="settings-panel-api" class="settings-panel active" data-settings-panel="api">
      <div class="section-title">API Configuration</div>
      <div class="section-sub">Connect your Groq AI key and Hugging Face Node.js backend</div>
      <div id="settings-group-api" class="card">
        <div class="form-group">
          <label class="form-label">Groq API Key</label>
          <div style="display:flex;gap:8px">
            <input class="form-input" type="password" id="setting-groq_api_key" data-setting-key="groq_api_key" placeholder="gsk_…">
            <button id="btn-test-groq" class="btn btn-secondary" style="flex-shrink:0">Test</button>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Get free key at <a href="https://console.groq.com" target="_blank" style="color:var(--indigo-light)">console.groq.com</a></div>
        </div>
        <div class="form-group">
          <label class="form-label">Groq Model</label>
          <select class="form-select" id="setting-groq_model" data-setting-key="groq_model">
            <option value="llama3-70b-8192">llama3-70b-8192 (Recommended)</option>
            <option value="llama3-8b-8192">llama3-8b-8192 (Faster)</option>
            <option value="mixtral-8x7b-32768">mixtral-8x7b-32768</option>
          </select>
        </div>
        <div class="divider"></div>
        <div class="form-group">
          <label class="form-label">Hugging Face Node API URL</label>
          <div style="display:flex;gap:8px">
            <input class="form-input" type="url" id="setting-node_api_url" data-setting-key="node_api_url" placeholder="https://your-space.hf.space">
            <button id="btn-test-node" class="btn btn-secondary" style="flex-shrink:0">Test</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Node API Key</label>
          <input class="form-input" type="password" id="setting-node_api_key" data-setting-key="node_api_key" placeholder="Your NODE_API_KEY">
        </div>
        <div class="form-group">
          <label class="form-label">Socket.io URL</label>
          <input class="form-input" type="url" id="setting-socket_url" data-setting-key="socket_url" placeholder="https://your-space.hf.space">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Webhook Secret</label>
          <input class="form-input" type="password" id="setting-webhook_secret" data-setting-key="webhook_secret" placeholder="Shared secret for webhook verification">
        </div>
        <div style="margin-top:16px">
          <button class="btn btn-primary btn-save-group" data-group="api">Save API Settings</button>
        </div>
      </div>
    </div>


    <!-- ── WHATSAPP PANEL ──────────────────────────────────── -->
    <div id="settings-panel-whatsapp" class="settings-panel" data-settings-panel="whatsapp">
      <div class="section-title">WhatsApp Engine</div>
      <div class="section-sub">Manage your WhatsApp session and connection</div>
      <div class="card" style="margin-bottom:16px">
        <div class="detail-row"><span class="detail-key">Status</span><span class="detail-val" id="wa-session-status-detail">—</span></div>
        <div class="detail-row"><span class="detail-key">Phone</span><span class="detail-val" id="wa-session-phone-detail">—</span></div>
        <div style="display:flex;gap:8px;margin-top:16px">
          <button id="btn-restart-wa" class="btn btn-secondary">↺ Restart Engine</button>
          <button id="btn-logout-wa" class="btn btn-danger">Logout WhatsApp</button>
        </div>
      </div>
      <div class="card">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:12px">How to Connect</div>
        <ol style="font-size:12px;color:var(--text-secondary);line-height:2;padding-left:18px">
          <li>Deploy the Node.js backend to Hugging Face Spaces</li>
          <li>Set the API URL above and save</li>
          <li>Go to Dashboard → click "Scan QR"</li>
          <li>Open WhatsApp → Settings → Linked Devices → Link a Device</li>
          <li>Scan the QR code shown in the dashboard</li>
        </ol>
      </div>
    </div>

    <!-- ── CAMPAIGN PANEL ──────────────────────────────────── -->
    <div id="settings-panel-campaign" class="settings-panel" data-settings-panel="campaign">
      <div class="section-title">Campaign Settings</div>
      <div class="section-sub">Anti-ban delays, daily limits, retry configuration</div>
      <div id="settings-group-campaign" class="card">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">Min Delay Between Messages (seconds)</label>
            <input class="form-input" type="number" id="setting-campaign_delay_min" data-setting-key="campaign_delay_min" min="30" max="3600">
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">Minimum: 30s recommended</div>
          </div>
          <div class="form-group">
            <label class="form-label">Max Delay Between Messages (seconds)</label>
            <input class="form-input" type="number" id="setting-campaign_delay_max" data-setting-key="campaign_delay_max" min="60" max="7200">
          </div>
          <div class="form-group">
            <label class="form-label">Daily Send Limit</label>
            <input class="form-input" type="number" id="setting-campaign_daily_limit" data-setting-key="campaign_daily_limit" min="1" max="500">
            <div style="font-size:10px;color:var(--text-muted);margin-top:4px">Stay under 50 for safety</div>
          </div>
          <div class="form-group">
            <label class="form-label">Max Retry Attempts</label>
            <input class="form-input" type="number" id="setting-campaign_retry_limit" data-setting-key="campaign_retry_limit" min="1" max="10">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="toggle-switch">
            <input type="checkbox" id="setting-auto_stop_on_reply" data-setting-key="auto_stop_on_reply">
            <span class="toggle-track"></span>
            <span style="font-size:13px;color:var(--text-secondary)">Auto-stop outreach when lead replies</span>
          </label>
        </div>
        <div style="margin-top:16px">
          <button class="btn btn-primary btn-save-group" data-group="campaign">Save Campaign Settings</button>
        </div>
      </div>
    </div>

    <!-- ── SELLER PROFILE PANEL ────────────────────────────── -->
    <div id="settings-panel-profile" class="settings-panel" data-settings-panel="profile">
      <div class="section-title">Seller Profile</div>
      <div class="section-sub">Your name, services, and CTA text used by AI for outreach messages</div>
      <div id="settings-group-profile" class="card">
        <div class="form-group">
          <label class="form-label">Your Name</label>
          <input class="form-input" type="text" id="setting-seller_name" data-setting-key="seller_name" placeholder="Your full name">
        </div>
        <div class="form-group">
          <label class="form-label">Portfolio URL</label>
          <input class="form-input" type="url" id="setting-seller_portfolio_url" data-setting-key="seller_portfolio_url" placeholder="https://yourportfolio.com">
        </div>
        <div class="form-group">
          <label class="form-label">Default CTA Text</label>
          <input class="form-input" type="text" id="setting-seller_cta_text" data-setting-key="seller_cta_text" placeholder="Would you be open to a quick chat?">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">App Display Name</label>
          <input class="form-input" type="text" id="setting-app_name" data-setting-key="app_name" placeholder="OutreachOS">
        </div>
        <div style="margin-top:16px">
          <button class="btn btn-primary btn-save-group" data-group="profile">Save Profile</button>
        </div>
      </div>
    </div>

    <!-- ── SECURITY PANEL ──────────────────────────────────── -->
    <div id="settings-panel-security" class="settings-panel" data-settings-panel="security">
      <div class="section-title">Security</div>
      <div class="section-sub">Change password, webhook IP whitelist, session settings</div>
      <div class="card" style="margin-bottom:16px">
        <div style="font-size:13px;font-weight:600;color:var(--text-primary);margin-bottom:14px">Change Password</div>
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <input class="form-input" type="password" id="current-password" placeholder="Enter current password">
        </div>
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input class="form-input" type="password" id="new-password" placeholder="Min 8 characters">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Confirm New Password</label>
          <input class="form-input" type="password" id="confirm-password" placeholder="Repeat new password">
        </div>
        <div style="margin-top:14px">
          <button id="btn-change-password" class="btn btn-primary">Update Password</button>
        </div>
      </div>
      <div id="settings-group-security" class="card">
        <div class="form-group">
          <label class="form-label">Webhook IP Whitelist (comma-separated)</label>
          <input class="form-input" type="text" id="setting-webhook_ip_whitelist" data-setting-key="webhook_ip_whitelist" placeholder="Leave empty to allow all IPs">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Session Timeout (seconds)</label>
          <input class="form-input" type="number" id="setting-session_timeout" data-setting-key="session_timeout" min="300" max="86400">
        </div>
        <div style="margin-top:14px">
          <button class="btn btn-primary btn-save-group" data-group="security">Save Security Settings</button>
        </div>
      </div>
    </div>

    <!-- ── FEATURES PANEL ──────────────────────────────────── -->
    <div id="settings-panel-features" class="settings-panel" data-settings-panel="features">
      <div class="section-title">Feature Flags</div>
      <div class="section-sub">Enable or disable system features</div>
      <div id="settings-group-features" class="card">
        <?php
        $featureFlags = [
            ['feature_ai_generation', 'AI Message Generation', 'Generate personalized outreach messages using Groq AI'],
            ['feature_validation',    'WhatsApp Validation',   'Check if phone numbers are registered on WhatsApp'],
            ['feature_campaign',      'Campaign Automation',   'Enable automated outreach queue system'],
            ['feature_logs',          'System Logging',        'Log all system events to database'],
            ['notification_sound',    'Notification Sounds',   'Play sounds for new messages and sent confirmations'],
            ['dark_mode',             'Dark Mode',             'Use dark theme (recommended)'],
        ];
        foreach ($featureFlags as [$key, $label, $desc]): ?>
        <div style="display:flex;align-items:flex-start;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:13px;font-weight:500;color:var(--text-primary)"><?= $label ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $desc ?></div>
          </div>
          <label class="toggle-switch" style="margin-left:16px;flex-shrink:0">
            <input type="checkbox" id="setting-<?= $key ?>" data-setting-key="<?= $key ?>">
            <span class="toggle-track"></span>
          </label>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:16px">
          <button class="btn btn-primary btn-save-group" data-group="features">Save Feature Settings</button>
        </div>
      </div>
    </div>

    <!-- ── LOGS PANEL ──────────────────────────────────────── -->
    <div id="settings-panel-logs" class="settings-panel" data-settings-panel="logs">
      <div class="section-title">System Logs</div>
      <div class="section-sub">View and manage application logs</div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <select id="log-level-filter" class="form-select" style="width:130px">
          <option value="">All Levels</option>
          <option value="info">Info</option>
          <option value="warning">Warning</option>
          <option value="error">Error</option>
          <option value="debug">Debug</option>
        </select>
        <select id="log-source-filter" class="form-select" style="width:140px">
          <option value="">All Sources</option>
          <option value="webhook">Webhook</option>
          <option value="campaign">Campaign</option>
          <option value="groq">Groq AI</option>
          <option value="api">API</option>
          <option value="auth">Auth</option>
          <option value="import">Import</option>
        </select>
        <div class="search-box" style="flex:1;min-width:180px">
          <svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input type="text" id="log-search" class="search-input" placeholder="Search logs…">
        </div>
        <button id="btn-purge-logs" class="btn btn-danger btn-sm">Purge Old Logs</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table>
          <thead><tr><th>Level</th><th>Source</th><th>Message</th><th>Time</th><th></th></tr></thead>
          <tbody id="logs-table-body">
            <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted)">Loading logs…</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /settings-main -->
</div><!-- /settings-layout -->

<div id="toast-container"></div>

<script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
<script src="/assets/js/ui-components.js"></script>
<script src="/assets/js/settings.js"></script>
<script>
window.CRM = {
  config: { socketUrl: '<?= htmlspecialchars(setting('socket_url', SOCKET_URL)) ?>', socketToken: '' },
  settings: { darkMode: true, notificationSound: true }
};

document.addEventListener('DOMContentLoaded', () => {
  // Tab switching
  document.querySelectorAll('[data-settings-tab]').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('[data-settings-tab]').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.settings-panel').forEach(p => p.style.display = 'none');
      tab.classList.add('active');
      const panel = document.getElementById('settings-panel-' + tab.dataset.settingsTab);
      if (panel) panel.style.display = 'block';
      if (tab.dataset.settingsTab === 'logs') Settings.loadLogs();
    });
  });
  Settings.init();
});
</script>
</body>
</html>
