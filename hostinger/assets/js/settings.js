// ============================================================
// settings.js — Settings page logic
// Handles: load settings, save settings, test connections,
//          change password, feature toggles, log viewer
// ============================================================

'use strict';

const Settings = (() => {

  // ── API helpers ───────────────────────────────────────────
  const api = async (url, options = {}) => {
    const defaults = { headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
    const res  = await fetch(url, { ...defaults, ...options, headers: { ...defaults.headers, ...(options.headers || {}) } });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data;
  };

  const apiGet  = (url) => api(url);
  const apiPost = (url, body) => api(url, { method: 'POST', body: JSON.stringify(body) });

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => [...document.querySelectorAll(sel)];

  // ── Load settings from API ────────────────────────────────
  const loadSettings = async () => {
    const container = $('#settings-content');
    if (container) container.innerHTML = `<div style="text-align:center;padding:40px"><div class="spinner" style="width:24px;height:24px;margin:0 auto"></div></div>`;

    try {
      const data = await apiGet('/api/get_settings.php');
      _renderSystemStatus(data.system_status);
      _renderWASession(data.whatsapp);
      _populateFields(data.settings);
      if (container) container.style.opacity = '1';
    } catch (e) {
      Toast.error('Failed to load settings: ' + e.message);
    }
  };

  // ── Render system status cards ────────────────────────────
  const _renderSystemStatus = (status) => {
    const checks = [
      { id: 'status-groq',    ok: status.groq_configured,    label: 'Groq API',       okMsg: 'Configured', failMsg: 'Not configured'   },
      { id: 'status-node',    ok: status.node_configured,    label: 'Node API URL',   okMsg: 'Configured', failMsg: 'Not configured'   },
      { id: 'status-nodekey', ok: status.node_key_set,       label: 'Node API Key',   okMsg: 'Set',        failMsg: 'Not set'          },
      { id: 'status-webhook', ok: status.webhook_secret_set, label: 'Webhook Secret', okMsg: 'Set',        failMsg: 'Not set'          },
      { id: 'status-pass',    ok: status.password_changed,   label: 'Admin Password', okMsg: 'Changed',    failMsg: '⚠ Using default'  },
      { id: 'status-online',  ok: status.node_online,        label: 'Node Server',    okMsg: 'Online ✓',   failMsg: 'Offline'          },
    ];

    checks.forEach(check => {
      const el = document.getElementById(check.id);
      if (!el) return;
      el.innerHTML = `
        <span style="color:${check.ok ? 'var(--emerald-light)' : 'var(--amber-light)'}">
          ${check.ok ? '✓' : '!'} ${check.label}: ${check.ok ? check.okMsg : check.failMsg}
        </span>`;
    });
  };

  // ── Render WA session info ────────────────────────────────
  const _renderWASession = (wa) => {
    const statusEl = document.getElementById('wa-session-status');
    const phoneEl  = document.getElementById('wa-session-phone');
    if (statusEl) statusEl.textContent = wa.status      || 'disconnected';
    if (phoneEl)  phoneEl.textContent  = wa.phone ? '+' + wa.phone : '—';
  };

  // ── Populate form fields ──────────────────────────────────
  const _populateFields = (settingGroups) => {
    if (!settingGroups) return;

    Object.entries(settingGroups).forEach(([group, items]) => {
      items.forEach(item => {
        const el = document.getElementById(`setting-${item.key}`);
        if (!el) return;

        if (el.type === 'checkbox') {
          el.checked = item.value === true || item.value === 1;
        } else if (el.tagName === 'SELECT') {
          el.value = item.is_set ? item.value : '';
        } else {
          // Don't fill sensitive fields (they show ●●●●)
          el.value = item.is_set && item.value === '••••••••••••••••' ? '' : (item.value || '');
          if (item.is_set && item.value === '••••••••••••••••') {
            el.placeholder = '••••••••••••••••  (saved)';
          }
        }
      });
    });
  };

  // ── Save a single setting ─────────────────────────────────
  const saveSetting = async (key, value) => {
    try {
      await apiPost('/api/update_settings.php', { action: 'update', key, value });
      return true;
    } catch (e) {
      Toast.error(`Failed to save ${key}: ${e.message}`);
      return false;
    }
  };

  // ── Save entire group ─────────────────────────────────────
  const saveGroup = async (groupName) => {
    const groupEl  = document.getElementById(`settings-group-${groupName}`);
    if (!groupEl) return;

    const inputs   = groupEl.querySelectorAll('[data-setting-key]');
    const settings = {};

    inputs.forEach(input => {
      const key = input.dataset.settingKey;
      if (!key) return;
      if (input.type === 'checkbox') settings[key] = input.checked;
      else if (input.value.trim() !== '' && input.value !== '••••••••••••••••') {
        settings[key] = input.value.trim();
      }
    });

    if (Object.keys(settings).length === 0) {
      Toast.info('No changes to save');
      return;
    }

    const btn = groupEl.querySelector('.btn-save-group');
    Loader.set(btn, true);

    try {
      await apiPost('/api/update_settings.php', { action: 'bulk_update', settings });
      Toast.success('Settings saved');
      loadSettings();
    } catch (e) {
      Toast.error('Save failed: ' + e.message);
    } finally {
      Loader.set(btn, false);
    }
  };

  // ── Test Node connection ──────────────────────────────────
  const testNodeConnection = async () => {
    const urlEl = document.getElementById('setting-node_api_url');
    const keyEl = document.getElementById('setting-node_api_key');
    const btn   = document.getElementById('btn-test-node');

    const url = urlEl?.value?.trim();
    const key = keyEl?.value?.trim();

    if (!url) { Toast.error('Enter Node API URL first'); return; }

    Loader.set(btn, true);
    try {
      const res = await apiPost('/api/update_settings.php', { action: 'test_node', url, key });
      if (res.online) Toast.success('✓ ' + res.message);
      else Toast.error('✕ ' + res.message);
    } catch (e) {
      Toast.error('Test failed: ' + e.message);
    } finally {
      Loader.set(btn, false);
    }
  };

  // ── Test Groq connection ──────────────────────────────────
  const testGroqConnection = async () => {
    const keyEl = document.getElementById('setting-groq_api_key');
    const btn   = document.getElementById('btn-test-groq');
    const key   = keyEl?.value?.trim();

    if (!key) { Toast.error('Enter Groq API key first'); return; }

    Loader.set(btn, true);
    try {
      const res = await apiPost('/api/update_settings.php', { action: 'test_groq', key });
      if (res.valid) {
        Toast.success(`✓ Valid! Models: ${(res.models || []).slice(0,3).join(', ')}`);
      } else {
        Toast.error('✕ Invalid API key');
      }
    } catch (e) {
      Toast.error('Test failed: ' + e.message);
    } finally {
      Loader.set(btn, false);
    }
  };

  // ── Change password ───────────────────────────────────────
  const changePassword = async () => {
    const current  = document.getElementById('current-password')?.value;
    const newPass  = document.getElementById('new-password')?.value;
    const confirm  = document.getElementById('confirm-password')?.value;
    const btn      = document.getElementById('btn-change-password');

    if (!current || !newPass || !confirm) { Toast.error('All fields required'); return; }
    if (newPass !== confirm) { Toast.error('New passwords do not match'); return; }
    if (newPass.length < 8)  { Toast.error('Password must be at least 8 characters'); return; }

    Loader.set(btn, true);
    try {
      await apiPost('/api/update_settings.php', { action: 'change_password', current_password: current, new_password: newPass, confirm_password: confirm });
      Toast.success('Password changed. Logging you out…');
      setTimeout(() => { window.location.href = '/logout.php'; }, 2000);
    } catch (e) {
      Toast.error(e.message);
    } finally {
      Loader.set(btn, false);
    }
  };

  // ── Load logs ─────────────────────────────────────────────
  const loadLogs = async (filters = {}) => {
    const container = document.getElementById('logs-table-body');
    if (!container) return;

    container.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spinner" style="margin:0 auto;width:20px;height:20px"></div></td></tr>`;

    const params = new URLSearchParams({ page: 1, per_page: 50, ...filters });

    try {
      const data = await apiGet(`/api/get_logs.php?${params}`);

      // Update summary badges
      document.getElementById('log-count-info')?.   setAttribute('data-count', data.summary.info    || 0);
      document.getElementById('log-count-warning')?.setAttribute('data-count', data.summary.warning || 0);
      document.getElementById('log-count-error')?.  setAttribute('data-count', data.summary.error   || 0);

      if (data.logs.length === 0) {
        container.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted)">No logs found</td></tr>`;
        return;
      }

      container.innerHTML = data.logs.map(log => `
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:8px 12px;font-size:11px">
            <span style="color:${ log.level === 'error' ? 'var(--red-light)' : log.level === 'warning' ? 'var(--amber-light)' : 'var(--text-muted)' };font-weight:600;text-transform:uppercase">${escHtml(log.level)}</span>
          </td>
          <td style="padding:8px 12px;font-size:11px;color:var(--text-muted)">${escHtml(log.source)}</td>
          <td style="padding:8px 12px;font-size:12px;color:var(--text-secondary);max-width:400px">${escHtml(log.message)}</td>
          <td style="padding:8px 12px;font-size:10px;color:var(--text-muted);white-space:nowrap">${escHtml(log.time_ago)}</td>
          <td style="padding:8px 12px">
            ${log.context ? `<button class="btn btn-sm btn-ghost" onclick="Settings.showLogContext(${log.id}, ${escHtml(JSON.stringify(log.context))})">Details</button>` : ''}
          </td>
        </tr>`).join('');

    } catch (e) {
      container.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--red-light)">${escHtml(e.message)}</td></tr>`;
    }
  };

  // ── Show log context modal ────────────────────────────────
  const showLogContext = (id, context) => {
    Modal.open({
      title:  `Log #${id} — Context`,
      width:  '500px',
      body:   `<pre style="background:var(--bg-elevated);padding:14px;border-radius:var(--radius-md);font-size:11px;color:var(--text-secondary);overflow-x:auto;white-space:pre-wrap;word-break:break-all">${escHtml(JSON.stringify(context, null, 2))}</pre>`,
    });
  };

  // ── Purge logs ────────────────────────────────────────────
  const purgeLogs = async (days = 30) => {
    Modal.confirm(`Delete all logs older than ${days} days?`, async () => {
      try {
        const res = await apiPost('/api/get_logs.php', { action: 'purge', days });
        Toast.success(`Deleted ${res.purged} log entries`);
        loadLogs();
      } catch (e) { Toast.error(e.message); }
    }, { danger: true, confirmText: 'Delete Logs' });
  };

  // ── WA Restart ────────────────────────────────────────────
  const restartWA = async () => {
    Modal.confirm('Restart WhatsApp engine? You may need to re-scan the QR code.', async () => {
      try {
        await apiGet('/api/refresh_sync.php?action=restart_wa');
        Toast.info('WhatsApp engine restarting…');
      } catch (e) { Toast.error(e.message); }
    });
  };

  // ── WA Logout ─────────────────────────────────────────────
  const logoutWA = async () => {
    Modal.confirm('Log out of WhatsApp? The session will be cleared and you\'ll need to re-scan.', async () => {
      try {
        await apiPost('/api/refresh_sync.php', { action: 'logout_wa' });
        Toast.success('WhatsApp logged out');
        loadSettings();
      } catch (e) { Toast.error(e.message); }
    }, { danger: true, confirmText: 'Logout WhatsApp' });
  };

  // ── Init ──────────────────────────────────────────────────
  const init = async () => {
    await loadSettings();

    // Bind save buttons
    $$('.btn-save-group').forEach(btn => {
      btn.addEventListener('click', () => saveGroup(btn.dataset.group));
    });

    // Test buttons
    document.getElementById('btn-test-node')?.addEventListener('click', testNodeConnection);
    document.getElementById('btn-test-groq')?.addEventListener('click', testGroqConnection);
    document.getElementById('btn-change-password')?.addEventListener('click', changePassword);
    document.getElementById('btn-restart-wa')?.addEventListener('click', restartWA);
    document.getElementById('btn-logout-wa')?.addEventListener('click', logoutWA);
    document.getElementById('btn-purge-logs')?.addEventListener('click', () => purgeLogs(30));

    // Log filters
    document.getElementById('log-level-filter')?.addEventListener('change', (e) => {
      loadLogs({ level: e.target.value });
    });
    document.getElementById('log-source-filter')?.addEventListener('change', (e) => {
      loadLogs({ source: e.target.value });
    });
    document.getElementById('log-search')?.addEventListener('input', debounce((e) => {
      loadLogs({ search: e.target.value });
    }, 400));

    // Load logs if tab is active
    if (document.getElementById('logs-table-body')) {
      await loadLogs();
    }

    // Settings tab navigation
    $$('[data-settings-tab]').forEach(tab => {
      tab.addEventListener('click', () => {
        $$('[data-settings-tab]').forEach(t => t.classList.remove('active'));
        $$('[data-settings-panel]').forEach(p => p.style.display = 'none');
        tab.classList.add('active');
        const panel = document.getElementById(`settings-panel-${tab.dataset.settingsTab}`);
        if (panel) panel.style.display = 'block';
      });
    });

    console.info('[Settings] Initialized');
  };

  return {
    init,
    loadSettings,
    saveSetting,
    saveGroup,
    testNodeConnection,
    testGroqConnection,
    changePassword,
    loadLogs,
    showLogContext,
    purgeLogs,
    restartWA,
    logoutWA,
  };

})();

window.Settings = Settings;

// Auto-init on settings page
if (document.getElementById('settings-page')) {
  document.addEventListener('DOMContentLoaded', () => Settings.init().catch(console.error));
}
