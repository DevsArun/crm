// ============================================================
// Socket Handler — All Socket.io event listeners
// Handles: WA events, messages, leads, campaigns, heartbeat
// Connects directly to HF Node Socket.io server
// ============================================================

'use strict';

const SocketHandler = (() => {

  let socket        = null;
  let isConnected   = false;
  let reconnectTimer= null;
  let heartbeatTimer= null;
  let missedPings   = 0;

  const MAX_MISSED   = 3;
  const HB_INTERVAL  = 35000; // 35s

  // ── Notification sound ────────────────────────────────────
  const _playSound = (type = 'notification') => {
    try {
      if (!window.CRM?.settings?.notificationSound) return;
      const src = type === 'sent' ? '/assets/sounds/sent.mp3' : '/assets/sounds/notification.mp3';
      const audio = new Audio(src);
      audio.volume = 0.4;
      audio.play().catch(() => {});
    } catch (_) {}
  };

  // ── Update WA status UI ───────────────────────────────────
  const _updateWAStatus = (status, phone = null, name = null) => {
    const dot   = document.getElementById('wa-status-dot');
    const label = document.getElementById('wa-status-label');
    const sub   = document.getElementById('wa-status-sub');

    if (!dot) return;

    dot.className = `wa-status-dot ${status}`;

    const labels = {
      connected:    'Connected',
      disconnected: 'Disconnected',
      qr_ready:     'Scan QR Code',
      connecting:   'Connecting...',
      auth_failure: 'Auth Failed',
      reconnecting: 'Reconnecting...',
    };

    if (label) label.textContent = labels[status] || 'Unknown';
    if (sub)   sub.textContent   = phone ? `+${phone}` : (name || '');

    // Dispatch global event for other handlers
    window.dispatchEvent(new CustomEvent('wa:status', { detail: { status, phone, name } }));
  };

  // ── Update socket connection UI ───────────────────────────
  const _updateSocketUI = (connected) => {
    const dot   = document.getElementById('socket-dot');
    const label = document.getElementById('socket-label');
    if (dot)   dot.className   = `socket-dot ${connected ? 'connected' : 'disconnected'}`;
    if (label) label.textContent = connected ? 'Live' : 'Offline';
  };

  // ── Update queue UI ───────────────────────────────────────
  const _updateQueueUI = (data) => {
    const qLen   = document.getElementById('queue-length');
    const qFill  = document.getElementById('queue-progress-fill');
    const qLabel = document.getElementById('queue-status-label');

    if (qLen)   qLen.textContent   = data.queueLength  || 0;
    if (qLabel) qLabel.textContent = data.isRunning ? (data.isPaused ? 'Paused' : 'Running') : 'Idle';

    if (qFill) {
      const pct = data.dailyLimit > 0
        ? Math.min(100, Math.round((data.sendCount / data.dailyLimit) * 100))
        : 0;
      qFill.style.width = pct + '%';
    }

    window.dispatchEvent(new CustomEvent('queue:updated', { detail: data }));
  };

  // ── Handle inbound message ────────────────────────────────
  const _onMessageReceived = (data) => {
    _playSound('notification');

    // Update unread count in middle column
    if (data.leadId) {
      const leadItem = document.querySelector(`[data-lead-id="${data.leadId}"]`);
      if (leadItem) {
        let dot = leadItem.querySelector('.unread-dot');
        if (!dot) {
          dot = document.createElement('span');
          dot.className = 'unread-dot';
          const bottom = leadItem.querySelector('.lead-item-bottom');
          if (bottom) bottom.prepend(dot);
        }

        // Update preview text
        const preview = leadItem.querySelector('.lead-preview');
        if (preview && data.body) preview.textContent = data.body;

        const timeEl = leadItem.querySelector('.lead-time');
        if (timeEl) timeEl.textContent = 'just now';

        // Move to top of list
        const list = leadItem.parentNode;
        if (list) list.prepend(leadItem);
      }
    }

    // If this lead is currently open, append message
    if (data.leadId && window.CRM?.activeLead?.id === data.leadId) {
      window.dispatchEvent(new CustomEvent('chat:newMessage', {
        detail: {
          id:           Date.now(),
          sender:       'lead',
          message_text: data.body,
          direction:    'inbound',
          is_read:      false,
          status:       'delivered',
          timestamp:    data.timestamp || new Date().toISOString(),
          time_ago:     'just now',
        },
      }));
    }

    // Update global unread badge
    window.dispatchEvent(new CustomEvent('stats:unread', { detail: { delta: 1 } }));
    window.dispatchEvent(new CustomEvent('message:received', { detail: data }));

    Toast.info(`📨 New message from ${data.phone || 'a lead'}`);
  };

  // ── Handle outbound sent ──────────────────────────────────
  const _onMessageSent = (data) => {
    _playSound('sent');

    if (data.leadId && window.CRM?.activeLead?.id === data.leadId) {
      window.dispatchEvent(new CustomEvent('chat:messageSent', { detail: data }));
    }

    window.dispatchEvent(new CustomEvent('message:sent', { detail: data }));
  };

  // ── Handle lead replied ───────────────────────────────────
  const _onLeadReplied = (data) => {
    // Update lead item status badge
    if (data.leadId) {
      const badge = document.querySelector(`[data-lead-id="${data.leadId}"] .outreach-badge`);
      if (badge) badge.outerHTML = Badge.outreachStatus('replied');
    }

    window.dispatchEvent(new CustomEvent('lead:replied', { detail: data }));
    Toast.success(`↩ Lead replied — check conversation`);
  };

  // ── Handle lead validated ─────────────────────────────────
  const _onLeadValidated = (data) => {
    // Find lead item by phone or leadId
    const selector = data.leadId
      ? `[data-lead-id="${data.leadId}"]`
      : `[data-phone="${data.phone}"]`;

    const item = document.querySelector(selector);
    if (item) {
      const waBadge = item.querySelector('.wa-badge');
      if (waBadge) waBadge.outerHTML = Badge.waStatus(data.status);
    }

    window.dispatchEvent(new CustomEvent('lead:validated', { detail: data }));
  };

  // ── Handle WA QR event ────────────────────────────────────
  const _onWAQR = (data) => {
    _updateWAStatus('qr_ready');

    const qrImg = document.getElementById('qr-image');
    if (qrImg && data.qr) {
      qrImg.src = data.qr;
    }

    window.dispatchEvent(new CustomEvent('wa:qr', { detail: data }));

    // Auto-show QR modal if exists
    const qrModal = document.getElementById('qr-modal-overlay');
    if (qrModal) qrModal.classList.add('visible');
  };

  // ── Heartbeat monitoring ──────────────────────────────────
  const _startHeartbeat = () => {
    clearInterval(heartbeatTimer);
    missedPings = 0;

    heartbeatTimer = setInterval(() => {
      if (!socket || !isConnected) return;

      socket.emit('pong:client');
      missedPings++;

      if (missedPings >= MAX_MISSED) {
        console.warn('[Socket] Too many missed pings — reconnecting');
        _reconnect();
      }
    }, HB_INTERVAL);
  };

  const _stopHeartbeat = () => clearInterval(heartbeatTimer);

  // ── Reconnect ─────────────────────────────────────────────
  const _reconnect = () => {
    if (socket) {
      try { socket.disconnect(); } catch (_) {}
    }
    clearTimeout(reconnectTimer);
    reconnectTimer = setTimeout(() => connect(), 3000);
  };

  // ── Main connect function ─────────────────────────────────
  const connect = () => {
    const socketUrl   = window.CRM?.config?.socketUrl;
    const authToken   = window.CRM?.config?.socketToken;

    if (!socketUrl || socketUrl.includes('your-space.hf.space')) {
      console.warn('[Socket] Socket URL not configured — skipping connection');
      _updateSocketUI(false);
      return;
    }

    if (typeof io === 'undefined') {
      console.error('[Socket] Socket.io client library not loaded');
      return;
    }

    _updateSocketUI('connecting');

    socket = io(socketUrl, {
      auth:        { token: authToken || '' },
      query:       { token: authToken || '' },
      transports:  ['websocket', 'polling'],
      reconnection:         true,
      reconnectionAttempts: 10,
      reconnectionDelay:    2000,
      reconnectionDelayMax: 15000,
      timeout:              20000,
    });

    // ── Connected ─────────────────────────────────────────
    socket.on('connect', () => {
      isConnected = true;
      missedPings = 0;
      _updateSocketUI(true);
      _startHeartbeat();
      console.info('[Socket] Connected:', socket.id);
      window.dispatchEvent(new CustomEvent('socket:connected'));
    });

    // ── Disconnect ────────────────────────────────────────
    socket.on('disconnect', (reason) => {
      isConnected = false;
      _updateSocketUI(false);
      _stopHeartbeat();
      console.warn('[Socket] Disconnected:', reason);
      window.dispatchEvent(new CustomEvent('socket:disconnected', { detail: { reason } }));
    });

    // ── Connect error ─────────────────────────────────────
    socket.on('connect_error', (err) => {
      console.error('[Socket] Connect error:', err.message);
      _updateSocketUI(false);
    });

    // ── Server heartbeat ──────────────────────────────────
    socket.on('heartbeat', (data) => {
      missedPings = 0; // Server confirmed alive
    });

    // ── System stats ──────────────────────────────────────
    socket.on('system:stats', (data) => {
      if (data.whatsapp) _updateWAStatus(data.whatsapp.status, data.whatsapp.phone);
      if (data.queue)    _updateQueueUI(data.queue);
    });

    // ── WhatsApp events ───────────────────────────────────
    socket.on('whatsapp:qr',           (d) => _onWAQR(d));
    socket.on('whatsapp:ready',        (d) => { _updateWAStatus('connected', d.phone, d.name); Toast.success('WhatsApp connected ✓'); });
    socket.on('whatsapp:disconnected', (d) => { _updateWAStatus('disconnected'); Toast.warning('WhatsApp disconnected'); });
    socket.on('whatsapp:auth_failure', (d) => { _updateWAStatus('auth_failure'); Toast.error('WhatsApp auth failed'); });
    socket.on('whatsapp:reconnecting', ()  => { _updateWAStatus('reconnecting'); });
    socket.on('whatsapp:loading',      (d) => { _updateWAStatus('connecting'); });

    // ── Message events ────────────────────────────────────
    socket.on('message:received', (d) => _onMessageReceived(d));
    socket.on('message:sent',     (d) => _onMessageSent(d));
    socket.on('message:status',   (d) => {
      window.dispatchEvent(new CustomEvent('message:status', { detail: d }));
    });

    // ── Lead events ───────────────────────────────────────
    socket.on('lead:replied',   (d) => _onLeadReplied(d));
    socket.on('lead:validated', (d) => _onLeadValidated(d));
    socket.on('lead:updated',   (d) => window.dispatchEvent(new CustomEvent('lead:updated', { detail: d })));

    // ── Campaign / outreach events ────────────────────────
    socket.on('outreach:started',  (d) => { Toast.info(`🚀 Outreach started — ${d.queueLength || 0} in queue`); window.dispatchEvent(new CustomEvent('outreach:started', { detail: d })); });
    socket.on('outreach:stopped',  (d) => window.dispatchEvent(new CustomEvent('outreach:stopped',  { detail: d })));
    socket.on('campaign:paused',   (d) => { Toast.warning('Campaign paused'); window.dispatchEvent(new CustomEvent('campaign:paused',   { detail: d })); });
    socket.on('campaign:resumed',  (d) => { Toast.success('Campaign resumed'); window.dispatchEvent(new CustomEvent('campaign:resumed',  { detail: d })); });
    socket.on('campaign:completed',(d) => { Toast.success('🎉 Campaign completed!'); window.dispatchEvent(new CustomEvent('campaign:completed', { detail: d })); });
    socket.on('queue:updated',     (d) => _updateQueueUI(d));
    socket.on('ai:generated',      (d) => window.dispatchEvent(new CustomEvent('ai:generated', { detail: d })));
    socket.on('error',             (d) => { console.error('[Socket] Server error:', d); Toast.error(d.message || 'Server error'); });
  };

  // ── Join a lead room ──────────────────────────────────────
  const joinLead = (leadId) => {
    if (socket && isConnected) socket.emit('join:lead', leadId);
  };

  const leaveLead = (leadId) => {
    if (socket && isConnected) socket.emit('leave:lead', leadId);
  };

  // ── Public API ────────────────────────────────────────────
  return {
    connect,
    joinLead,
    leaveLead,
    isConnected: () => isConnected,
    getSocket:   () => socket,
    disconnect:  () => { if (socket) socket.disconnect(); _stopHeartbeat(); },
  };

})();

window.SocketHandler = SocketHandler;
