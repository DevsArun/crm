// ============================================================
// app.js — Core application bootstrap
// Initializes: global CRM config, socket connection,
//              dashboard, keyboard shortcuts, global events
// Must be loaded LAST on dashboard.php
// ============================================================

'use strict';

// ── Global CRM namespace (set by PHP via inline script) ───────
window.CRM = window.CRM || {
  config: {
    socketUrl:    '',
    socketToken:  '',
    appName:      'OutreachOS',
    timezone:     'Asia/Kolkata',
  },
  settings: {
    notificationSound: true,
    darkMode:          true,
  },
  activeLead: null,
  version:    '1.0.0',
};

// ── App Init ──────────────────────────────────────────────────
const App = (() => {

  // ── Initialize everything ─────────────────────────────────
  const init = async () => {
    console.info(`[App] ${window.CRM.config.appName} v${window.CRM.version} — initializing`);

    _applyTheme();
    _setupKeyboardShortcuts();
    _setupGlobalErrorHandler();
    _setupNetworkMonitor();

    // Initialize Socket.io connection
    SocketHandler.connect();

    // Initialize Dashboard
    if (typeof Dashboard !== 'undefined') {
      await Dashboard.init();
    }

    // Mark page as ready
    document.body.classList.remove('loading');
    document.body.classList.add('app-ready');

    console.info('[App] Ready');
  };

  // ── Dark mode / theme ─────────────────────────────────────
  const _applyTheme = () => {
    const dark = window.CRM.settings.darkMode !== false;
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  };

  // ── Keyboard shortcuts ────────────────────────────────────
  const _setupKeyboardShortcuts = () => {
    document.addEventListener('keydown', (e) => {
      // Escape: close modal/drawer
      if (e.key === 'Escape') {
        Modal.close();
        Drawer.close();
        return;
      }

      // Ctrl/Cmd + K: focus search
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const search = document.getElementById('search-leads');
        if (search) { search.focus(); search.select(); }
        return;
      }

      // Ctrl/Cmd + Enter: send message when textarea focused
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const active = document.activeElement;
        if (active?.id === 'chat-textarea') {
          e.preventDefault();
          Dashboard?.sendMessage?.();
        }
        return;
      }

      // Ctrl/Cmd + R: refresh stats (prevent browser refresh)
      if ((e.ctrlKey || e.metaKey) && e.key === 'r' && !e.shiftKey) {
        // Only intercept if not in an input
        if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) {
          e.preventDefault();
          Dashboard?.loadStats?.();
          Toast.info('Stats refreshed');
        }
      }
    });
  };

  // ── Global error handler ──────────────────────────────────
  const _setupGlobalErrorHandler = () => {
    window.addEventListener('error', (e) => {
      console.error('[App] Uncaught error:', e.message, e.filename, e.lineno);
    });

    window.addEventListener('unhandledrejection', (e) => {
      console.error('[App] Unhandled promise rejection:', e.reason);
      // Don't show toast for network errors (too noisy)
    });
  };

  // ── Network monitor ───────────────────────────────────────
  const _setupNetworkMonitor = () => {
    const onOnline = () => {
      Toast.success('Connection restored');
      SocketHandler.connect();
      Dashboard?.loadStats?.();
    };

    const onOffline = () => {
      Toast.warning('You are offline — live updates paused');
    };

    window.addEventListener('online',  onOnline);
    window.addEventListener('offline', onOffline);
  };

  // ── Notification permission request ──────────────────────
  const requestNotificationPermission = async () => {
    if ('Notification' in window && Notification.permission === 'default') {
      try {
        const perm = await Notification.requestPermission();
        if (perm === 'granted') {
          console.info('[App] Browser notifications granted');
        }
      } catch (_) {}
    }
  };

  // ── Show browser notification ─────────────────────────────
  const notify = (title, body, icon = '/favicon.ico') => {
    if (!document.hidden) return; // Only notify when tab is in background
    if (Notification.permission !== 'granted') return;
    try {
      new Notification(title, { body, icon, badge: icon });
    } catch (_) {}
  };

  // ── Format page title with unread count ──────────────────
  const setUnreadCount = (count) => {
    const appName = window.CRM.config.appName || 'OutreachOS';
    document.title = count > 0 ? `(${count}) ${appName}` : appName;
  };

  // ── Listen for unread updates ─────────────────────────────
  window.addEventListener('stats:unread', (e) => {
    const delta  = e.detail?.delta || 0;
    const badge  = document.getElementById('nav-unread-badge');
    const current= parseInt(badge?.textContent || '0', 10);
    const updated= Math.max(0, current + delta);

    if (badge) {
      badge.textContent   = updated;
      badge.style.display = updated > 0 ? 'flex' : 'none';
    }

    setUnreadCount(updated);

    // Browser notification on new message
    if (delta > 0) {
      const lead = window.CRM.activeLead;
      notify(
        'New WhatsApp Message',
        lead?.business_name ? `New message from ${lead.business_name}` : 'You have a new message',
      );
    }
  });

  // ── Public API ────────────────────────────────────────────
  return {
    init,
    notify,
    setUnreadCount,
    requestNotificationPermission,
  };

})();

// ── Boot on DOM ready ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  App.init().catch(console.error);
  App.requestNotificationPermission();
});

window.App = App;
