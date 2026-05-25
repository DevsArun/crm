// ============================================================
// UI Components — Reusable UI: toasts, modals, skeletons,
// drawers, confirms, badges, loaders, animations
// ============================================================

'use strict';

// ── Toast Notification System ─────────────────────────────────
const Toast = (() => {
  let container = null;

  const _getContainer = () => {
    if (!container) {
      container = document.getElementById('toast-container');
      if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
      }
    }
    return container;
  };

  const show = (message, type = 'info', duration = 3500) => {
    const c    = _getContainer();
    const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type} fade-in`;

    toast.innerHTML = `
      <span class="toast-icon">${icons[type] || icons.info}</span>
      <span class="toast-text">${message}</span>
      <span class="toast-close" onclick="this.closest('.toast').remove()">×</span>
    `;

    c.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity    = '0';
      toast.style.transform  = 'translateX(20px)';
      toast.style.transition = 'all 0.2s ease';
      setTimeout(() => toast.remove(), 200);
    }, duration);

    return toast;
  };

  return {
    success: (msg, dur) => show(msg, 'success', dur),
    error:   (msg, dur) => show(msg, 'error',   dur || 5000),
    warning: (msg, dur) => show(msg, 'warning', dur),
    info:    (msg, dur) => show(msg, 'info',    dur),
  };
})();

// ── Modal System ──────────────────────────────────────────────
const Modal = (() => {
  let activeModal = null;

  const open = (options = {}) => {
    const {
      title    = '',
      body     = '',
      footer   = null,
      width    = '560px',
      onClose  = null,
      id       = 'modal-' + Date.now(),
    } = options;

    // Close existing
    close();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id        = id + '-overlay';

    overlay.innerHTML = `
      <div class="modal" style="max-width:${width}">
        <div class="modal-header">
          <span class="modal-title">${title}</span>
          <button class="icon-btn" onclick="Modal.close()" title="Close">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M18 6L6 18M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="modal-body">${body}</div>
        ${footer ? `<div class="modal-footer">${footer}</div>` : ''}
      </div>
    `;

    // Close on overlay click
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) { close(); if (onClose) onClose(); }
    });

    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('visible'));

    activeModal = { overlay, onClose };
    return overlay;
  };

  const close = () => {
    if (!activeModal) return;
    activeModal.overlay.classList.remove('visible');
    setTimeout(() => { activeModal?.overlay?.remove(); activeModal = null; }, 200);
  };

  const confirm = (message, onConfirm, options = {}) => {
    const {
      title       = 'Confirm',
      confirmText = 'Confirm',
      cancelText  = 'Cancel',
      danger      = false,
    } = options;

    const btnClass = danger ? 'btn btn-danger' : 'btn btn-primary';

    open({
      title,
      body: `<p style="color:var(--text-secondary);font-size:13px;line-height:1.6">${message}</p>`,
      footer: `
        <button class="btn btn-secondary" onclick="Modal.close()">${cancelText}</button>
        <button class="${btnClass}" id="confirm-btn">${confirmText}</button>
      `,
    });

    document.getElementById('confirm-btn').addEventListener('click', () => {
      close();
      if (onConfirm) onConfirm();
    });
  };

  return { open, close, confirm };
})();

// ── Drawer System ─────────────────────────────────────────────
const Drawer = (() => {
  let activeDrawer   = null;
  let activeOverlay  = null;

  const open = (options = {}) => {
    const {
      title   = '',
      body    = '',
      onClose = null,
      id      = 'drawer-' + Date.now(),
    } = options;

    close();

    // Overlay
    const overlay = document.createElement('div');
    overlay.className = 'drawer-overlay';
    overlay.addEventListener('click', () => { close(); if (onClose) onClose(); });
    document.body.appendChild(overlay);

    // Drawer
    const drawer = document.createElement('div');
    drawer.className = 'drawer';
    drawer.id        = id;
    drawer.innerHTML = `
      <div class="drawer-header">
        <span class="drawer-title">${title}</span>
        <button class="icon-btn" onclick="Drawer.close()" title="Close">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M18 6L6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="drawer-body">${body}</div>
    `;

    document.body.appendChild(drawer);

    requestAnimationFrame(() => {
      overlay.classList.add('visible');
      drawer.classList.add('open');
    });

    activeDrawer  = drawer;
    activeOverlay = overlay;
    return drawer;
  };

  const setContent = (html) => {
    if (activeDrawer) {
      const body = activeDrawer.querySelector('.drawer-body');
      if (body) body.innerHTML = html;
    }
  };

  const close = () => {
    if (activeDrawer)  { activeDrawer.classList.remove('open'); setTimeout(() => activeDrawer?.remove(), 250); activeDrawer = null; }
    if (activeOverlay) { activeOverlay.classList.remove('visible'); setTimeout(() => activeOverlay?.remove(), 200); activeOverlay = null; }
  };

  return { open, close, setContent };
})();

// ── Loading Skeleton Builder ──────────────────────────────────
const Skeleton = {
  leadItem: () => `
    <div class="lead-item" style="pointer-events:none">
      <div class="lead-item-top">
        <div class="skeleton skeleton-line w-3-4" style="height:13px"></div>
        <div class="skeleton skeleton-line w-1-4" style="height:10px"></div>
      </div>
      <div class="lead-item-mid" style="margin-top:6px">
        <div class="skeleton skeleton-line" style="width:60px;height:10px"></div>
      </div>
      <div class="lead-item-bottom" style="margin-top:6px">
        <div class="skeleton skeleton-line w-3-4" style="height:10px"></div>
      </div>
    </div>`,

  leadList: (count = 8) => Array(count).fill(0).map(() => Skeleton.leadItem()).join(''),

  message: (outbound = false) => `
    <div class="message-row ${outbound ? 'outbound' : 'inbound'}" style="pointer-events:none">
      <div class="skeleton" style="width:${40 + Math.random()*30|0}%;height:42px;border-radius:14px"></div>
    </div>`,

  chatMessages: (count = 6) => Array(count).fill(0).map((_, i) => Skeleton.message(i % 3 === 0)).join(''),

  statCard: () => `
    <div class="kpi-card">
      <div class="skeleton skeleton-line" style="height:24px;width:60px"></div>
      <div class="skeleton skeleton-line w-3-4" style="height:10px;margin-top:6px"></div>
    </div>`,
};

// ── Badge builder ─────────────────────────────────────────────
const Badge = {
  waStatus: (status) => {
    const map = {
      valid:           { cls: 'badge-valid',    label: '✓ WA Valid'  },
      invalid:         { cls: 'badge-invalid',  label: '✕ Invalid'   },
      not_on_whatsapp: { cls: 'badge-no-wa',    label: 'No WhatsApp' },
      pending:         { cls: 'badge-pending',  label: '⋯ Pending'   },
      failed:          { cls: 'badge-failed',   label: '! Failed'    },
    };
    const b = map[status] || { cls: 'badge-no-wa', label: status };
    return `<span class="badge ${b.cls}">${b.label}</span>`;
  },

  outreachStatus: (status) => {
    const map = {
      pending:  { cls: 'badge-pending',  label: 'Pending'  },
      queued:   { cls: 'badge-queued',   label: '⋯ Queued' },
      sent:     { cls: 'badge-sent',     label: '↑ Sent'   },
      replied:  { cls: 'badge-replied',  label: '↩ Replied'},
      failed:   { cls: 'badge-failed',   label: '✕ Failed' },
      skipped:  { cls: 'badge-skipped',  label: 'Skipped'  },
    };
    const b = map[status] || { cls: 'badge-pending', label: status };
    return `<span class="badge ${b.cls}">${b.label}</span>`;
  },

  website: (status) => status === 'has_website'
    ? `<span class="badge badge-website">🌐 Website</span>`
    : `<span class="badge badge-no-website">No Site</span>`,

  engagement: (score) => {
    const map = {
      hot:  { color: '#f87171', label: '🔥 Hot'  },
      warm: { color: '#fbbf24', label: '⚡ Warm' },
      cool: { color: '#60a5fa', label: '💧 Cool' },
      cold: { color: '#9ca3af', label: '❄ Cold'  },
    };
    const b = map[score] || map.cold;
    return `<span style="color:${b.color};font-size:11px;font-weight:600">${b.label}</span>`;
  },
};

// ── Progress / Loading state manager ─────────────────────────
const Loader = {
  set: (el, loading = true, originalText = null) => {
    if (!el) return;
    if (loading) {
      el._originalHTML = el.innerHTML;
      el.innerHTML = `<span class="spinner"></span>`;
      el.disabled  = true;
    } else {
      el.innerHTML = originalText || el._originalHTML || el.innerHTML;
      el.disabled  = false;
    }
  },

  setBtn: (selector, loading, text = null) => {
    const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
    Loader.set(el, loading, text);
  },
};

// ── Auto-resize textarea ──────────────────────────────────────
const autoResizeTextarea = (textarea) => {
  textarea.style.height = 'auto';
  textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
};

// ── Format file size ──────────────────────────────────────────
const formatFileSize = (bytes) => {
  if (bytes < 1024)       return bytes + ' B';
  if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1048576).toFixed(1) + ' MB';
};

// ── Time ago (client-side) ────────────────────────────────────
const timeAgo = (dateStr) => {
  if (!dateStr) return '—';
  const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return `${Math.floor(diff/60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
  return `${Math.floor(diff/86400)}d ago`;
};

// ── Escape HTML ───────────────────────────────────────────────
const escHtml = (str) => {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
};

// ── Copy to clipboard ─────────────────────────────────────────
const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text);
    Toast.success('Copied to clipboard');
  } catch {
    Toast.error('Could not copy to clipboard');
  }
};

// ── Debounce ──────────────────────────────────────────────────
const debounce = (fn, delay = 300) => {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
};

// ── Throttle ──────────────────────────────────────────────────
const throttle = (fn, limit = 300) => {
  let last = 0;
  return (...args) => {
    const now = Date.now();
    if (now - last >= limit) { last = now; fn(...args); }
  };
};

// ── Smooth scroll to bottom ───────────────────────────────────
const scrollToBottom = (el, smooth = true) => {
  if (!el) return;
  el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
};

// ── Expose globally ───────────────────────────────────────────
window.Toast          = Toast;
window.Modal          = Modal;
window.Drawer         = Drawer;
window.Skeleton       = Skeleton;
window.Badge          = Badge;
window.Loader         = Loader;
window.timeAgo        = timeAgo;
window.escHtml        = escHtml;
window.copyToClipboard= copyToClipboard;
window.debounce       = debounce;
window.throttle       = throttle;
window.scrollToBottom = scrollToBottom;
window.autoResizeTextarea = autoResizeTextarea;
window.formatFileSize     = formatFileSize;
