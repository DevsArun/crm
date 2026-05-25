// ============================================================
// Dashboard.js — Core dashboard logic
// Handles: leads list, messages, KPIs, campaign controls,
//          lead details drawer, AI generation, validation,
//          CSV upload, all AJAX interactions
// ============================================================

'use strict';

const Dashboard = (() => {

  // ── State ─────────────────────────────────────────────────
  const state = {
    leads:        [],
    activeLead:   null,
    messages:     [],
    page:         1,
    perPage:      30,
    totalLeads:   0,
    filters:      { search: '', wa_status: '', outreach_status: '', website_status: '', pitch_type: '' },
    stats:        {},
    campaignId:   1,
    isLoadingLeads:    false,
    isLoadingMessages: false,
    isSendingMessage:  false,
  };

  // ── API helper ────────────────────────────────────────────
  const api = async (url, options = {}) => {
    const defaults = {
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    };
    const res  = await fetch(url, { ...defaults, ...options, headers: { ...defaults.headers, ...(options.headers || {}) } });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Request failed');
    return data;
  };

  const apiGet  = (url) => api(url);
  const apiPost = (url, body) => api(url, { method: 'POST', body: JSON.stringify(body) });

  // ── DOM helpers ───────────────────────────────────────────
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  // ============================================================
  // STATS / KPIs
  // ============================================================
  const loadStats = async () => {
    try {
      const data = await apiGet('/api/get_stats.php');
      state.stats = data;
      _renderKPIs(data);
      _renderWAStatus(data.whatsapp);
      _renderQueueState(data.queue);
    } catch (e) {
      console.error('[Dashboard] Stats load failed:', e.message);
    }
  };

  const _renderKPIs = (data) => {
    const leads    = data.leads    || {};
    const today    = data.today    || {};
    const messages = data.messages || {};

    _setText('kpi-total-leads',    leads.total             || 0);
    _setText('kpi-valid-wa',       leads.wa_valid          || 0);
    _setText('kpi-sent',           leads.outreach_sent     || 0);
    _setText('kpi-replied',        leads.outreach_replied  || 0);
    _setText('kpi-unread',         messages.unread         || 0);
    _setText('kpi-reply-rate',     (leads.reply_rate || 0) + '%');
    _setText('kpi-sent-today',     today.sent_today        || 0);
    _setText('kpi-replied-today',  today.replied_today     || 0);

    // Unread badge in nav
    const unreadBadge = $('#nav-unread-badge');
    if (unreadBadge) {
      const count = messages.unread || 0;
      unreadBadge.textContent = count;
      unreadBadge.style.display = count > 0 ? 'flex' : 'none';
    }
  };

  const _renderWAStatus = (wa) => {
    if (!wa) return;
    const dot   = $('#wa-status-dot');
    const label = $('#wa-status-label');
    const sub   = $('#wa-status-sub');
    if (dot)   dot.className   = `wa-status-dot ${wa.status || 'disconnected'}`;
    if (label) label.textContent = _waLabel(wa.status);
    if (sub)   sub.textContent   = wa.phone ? `+${wa.phone}` : (wa.name || '');
  };

  const _renderQueueState = (q) => {
    if (!q) return;
    _setText('queue-length',       q.length     || 0);
    _setText('queue-send-count',   q.send_count || 0);
    _setText('queue-daily-limit',  q.daily_limit|| 50);
    _setText('queue-status-label', q.is_running ? (q.is_paused ? 'Paused' : 'Running') : 'Idle');
    const fill = $('#queue-progress-fill');
    if (fill && q.daily_limit > 0) {
      fill.style.width = Math.min(100, Math.round((q.send_count / q.daily_limit) * 100)) + '%';
    }
  };

  const _waLabel = (s) => ({
    connected: 'Connected', disconnected: 'Disconnected',
    qr_ready: 'Scan QR Code', connecting: 'Connecting…',
    auth_failure: 'Auth Failed', reconnecting: 'Reconnecting…',
  }[s] || 'Unknown');

  // ============================================================
  // LEADS LIST
  // ============================================================
  const loadLeads = async (reset = false) => {
    if (state.isLoadingLeads) return;
    state.isLoadingLeads = true;

    if (reset) { state.page = 1; state.leads = []; }

    const list = $('#lead-list');
    if (list && reset) list.innerHTML = Skeleton.leadList(8);

    const f = state.filters;
    const params = new URLSearchParams({
      page:             state.page,
      per_page:         state.perPage,
      search:           f.search          || '',
      wa_status:        f.wa_status       || '',
      outreach_status:  f.outreach_status || '',
      website_status:   f.website_status  || '',
      pitch_type:       f.pitch_type      || '',
      campaign_id:      state.campaignId  || '',
    });

    try {
      const data = await apiGet(`/api/get_leads.php?${params}`);
      state.totalLeads = data.pagination.total;

      if (reset) state.leads = data.leads;
      else       state.leads = [...state.leads, ...data.leads];

      _renderLeadList(data.leads, reset);
      _renderPagination(data.pagination);

    } catch (e) {
      if (list && reset) list.innerHTML = `<div class="empty-state"><div class="empty-icon">⚠</div><div class="empty-title">Failed to load leads</div><div class="empty-sub">${escHtml(e.message)}</div></div>`;
    } finally {
      state.isLoadingLeads = false;
    }
  };

  const _renderLeadList = (leads, reset = false) => {
    const list = $('#lead-list');
    if (!list) return;

    if (reset) list.innerHTML = '';

    if (leads.length === 0 && reset) {
      list.innerHTML = `<div class="empty-state"><div class="empty-icon">🔍</div><div class="empty-title">No leads found</div><div class="empty-sub">Try adjusting filters or import a CSV file</div></div>`;
      return;
    }

    leads.forEach(lead => {
      const existing = list.querySelector(`[data-lead-id="${lead.id}"]`);
      if (existing) { existing.outerHTML = _leadItemHTML(lead); return; }
      const el = document.createElement('div');
      el.innerHTML = _leadItemHTML(lead);
      list.appendChild(el.firstElementChild);
    });

    // Bind click events
    list.querySelectorAll('.lead-item[data-lead-id]').forEach(item => {
      item.addEventListener('click', () => selectLead(parseInt(item.dataset.leadId)));
    });
  };

  const _leadItemHTML = (lead) => {
    const initials = (lead.business_name || '?').substring(0, 2).toUpperCase();
    const location = [lead.locality, lead.city].filter(Boolean).join(', ') || lead.state || '—';
    const preview  = lead.last_message || (lead.has_generated_message ? '✦ AI message ready' : 'No messages yet');
    const time     = lead.last_message_ago || lead.created_ago || '';

    return `
      <div class="lead-item ${lead.is_pinned ? 'pinned' : ''} ${state.activeLead?.id === lead.id ? 'active' : ''}"
           data-lead-id="${lead.id}" data-phone="${escHtml(lead.phone_normalized)}">
        <div class="lead-item-top">
          <span class="lead-name">${escHtml(lead.business_name)}</span>
          <span class="lead-time">${escHtml(time)}</span>
        </div>
        <div class="lead-item-mid">
          <span class="lead-city">${escHtml(location)}</span>
          <span class="wa-badge">${Badge.waStatus(lead.whatsapp_status)}</span>
          <span class="outreach-badge">${Badge.outreachStatus(lead.outreach_status)}</span>
        </div>
        <div class="lead-item-bottom">
          ${lead.unread_count > 0 ? '<span class="unread-dot"></span>' : ''}
          <span class="lead-preview">${escHtml(preview)}</span>
        </div>
      </div>`;
  };

  const _renderPagination = (pg) => {
    const loadMore = $('#load-more-leads');
    if (loadMore) loadMore.style.display = pg.has_next ? 'block' : 'none';
    _setText('leads-count-label', `${pg.total} leads`);
  };


  // ============================================================
  // SELECT LEAD & LOAD MESSAGES
  // ============================================================
  const selectLead = async (leadId) => {
    if (state.activeLead?.id === leadId) return;

    // Leave previous lead socket room
    if (state.activeLead?.id) SocketHandler.leaveLead(state.activeLead.id);

    // Update active state in list
    $$('.lead-item').forEach(el => el.classList.remove('active'));
    const item = $(`[data-lead-id="${leadId}"]`);
    if (item) item.classList.add('active');

    // Show loading in right column
    _showChatSkeleton();

    try {
      const data = await apiGet(`/api/get_messages.php?lead_id=${leadId}`);
      state.activeLead = data.lead;
      state.messages   = data.messages;

      window.CRM.activeLead = data.lead;

      _renderChatHeader(data.lead);
      _renderMessages(data.messages, true);
      _renderAIMessageBanner(data.lead);
      _updateUnreadBadge(leadId, 0);

      // Join socket room for live updates
      SocketHandler.joinLead(leadId);

    } catch (e) {
      Toast.error('Failed to load conversation: ' + e.message);
    }
  };

  const _showChatSkeleton = () => {
    const msgs = $('#chat-messages');
    if (msgs) msgs.innerHTML = Skeleton.chatMessages(6);
    const header = $('#chat-title');
    if (header) header.textContent = 'Loading…';
  };

  const _renderChatHeader = (lead) => {
    const initials = (lead.business_name || '?').substring(0, 2).toUpperCase();
    const avatar   = $('#chat-avatar');
    const title    = $('#chat-title');
    const subtitle = $('#chat-subtitle');
    const phone    = $('#chat-phone-display');

    if (avatar)   avatar.textContent  = initials;
    if (title)    title.textContent   = lead.business_name || '—';
    if (subtitle) subtitle.textContent= [lead.city, lead.state].filter(Boolean).join(', ');
    if (phone)    phone.textContent   = lead.phone_display  || '';

    // Update header badges
    const waEl  = $('#header-wa-badge');
    const outEl = $('#header-outreach-badge');
    if (waEl)  waEl.innerHTML  = Badge.waStatus(lead.whatsapp_status);
    if (outEl) outEl.innerHTML = Badge.outreachStatus(lead.outreach_status);

    // Show/hide reply notice
    const replyNotice = $('#reply-notice');
    if (replyNotice) {
      replyNotice.style.display = lead.outreach_status === 'replied' ? 'flex' : 'none';
    }

    // Enable/disable send button
    const sendBtn = $('#send-message-btn');
    if (sendBtn) sendBtn.disabled = lead.whatsapp_status !== 'valid';
  };

  const _renderAIMessageBanner = (lead) => {
    const banner = $('#ai-message-banner');
    if (!banner) return;
    if (lead.has_ai_message && lead.outreach_status === 'pending') {
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }
  };

  const _renderMessages = (messages, reset = false) => {
    const container = $('#chat-messages');
    if (!container) return;

    if (reset) container.innerHTML = '';

    if (messages.length === 0 && reset) {
      container.innerHTML = `<div class="empty-state"><div class="empty-icon">💬</div><div class="empty-title">No messages yet</div><div class="empty-sub">First outreach message will appear here once sent</div></div>`;
      return;
    }

    let lastDate = null;
    messages.forEach(msg => {
      const msgDate = msg.timestamp ? msg.timestamp.substring(0, 10) : null;
      if (msgDate && msgDate !== lastDate) {
        const divEl = document.createElement('div');
        divEl.className = 'chat-date-divider';
        divEl.textContent = _formatDateLabel(msgDate);
        container.appendChild(divEl);
        lastDate = msgDate;
      }
      container.insertAdjacentHTML('beforeend', _messageBubbleHTML(msg));
    });

    scrollToBottom(container, false);
  };

  const appendMessage = (msg) => {
    const container = $('#chat-messages');
    if (!container) return;
    // Remove empty state if present
    const empty = container.querySelector('.empty-state');
    if (empty) empty.remove();
    container.insertAdjacentHTML('beforeend', _messageBubbleHTML(msg));
    scrollToBottom(container);
    state.messages.push(msg);
  };

  const _messageBubbleHTML = (msg) => {
    const isOut  = msg.direction === 'outbound';
    const text   = escHtml(msg.message_text || '').replace(/\n/g, '<br>');
    const time   = msg.time_ago || '';
    const status = isOut ? _tickIcon(msg.status) : '';

    return `
      <div class="message-row ${isOut ? 'outbound' : 'inbound'}" data-msg-id="${msg.id || ''}">
        <div class="bubble bubble-${isOut ? 'outbound' : 'inbound'}">
          <div>${text}</div>
          <div class="bubble-meta">
            <span class="bubble-time">${escHtml(time)}</span>
            ${status ? `<span class="bubble-status">${status}</span>` : ''}
          </div>
        </div>
      </div>`;
  };

  const _tickIcon = (status) => ({
    sent:      '<span class="tick-sent">✓</span>',
    delivered: '<span class="tick-delivered">✓✓</span>',
    read:      '<span class="tick-read">✓✓</span>',
  }[status] || '');

  const _formatDateLabel = (dateStr) => {
    const today = new Date().toISOString().substring(0, 10);
    const yest  = new Date(Date.now() - 86400000).toISOString().substring(0, 10);
    if (dateStr === today) return 'Today';
    if (dateStr === yest)  return 'Yesterday';
    return new Date(dateStr).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  };

  const _updateUnreadBadge = (leadId, count) => {
    const item = $(`[data-lead-id="${leadId}"]`);
    if (!item) return;
    const dot = item.querySelector('.unread-dot');
    if (count === 0 && dot) dot.remove();
    else if (count > 0 && !dot) {
      const span = document.createElement('span');
      span.className = 'unread-dot';
      item.querySelector('.lead-item-bottom')?.prepend(span);
    }
  };

  // ============================================================
  // SEND MANUAL MESSAGE
  // ============================================================
  const sendMessage = async () => {
    if (!state.activeLead || state.isSendingMessage) return;

    const textarea = $('#chat-textarea');
    const sendBtn  = $('#send-message-btn');
    const message  = textarea?.value?.trim();

    if (!message) return;
    if (state.activeLead.whatsapp_status !== 'valid') {
      Toast.error('This lead does not have a valid WhatsApp number');
      return;
    }

    state.isSendingMessage = true;
    Loader.set(sendBtn, true);
    textarea.disabled = true;

    // Optimistic UI: show message immediately
    const tempId = 'temp-' + Date.now();
    appendMessage({
      id:           tempId,
      sender:       'user',
      message_text: message,
      direction:    'outbound',
      status:       'pending',
      timestamp:    new Date().toISOString(),
      time_ago:     'sending…',
    });

    if (textarea) { textarea.value = ''; autoResizeTextarea(textarea); }

    try {
      const data = await apiPost('/api/send_manual.php', {
        lead_id: state.activeLead.id,
        message,
      });

      // Update temp message with real ID
      const tempEl = $(`[data-msg-id="${tempId}"]`);
      if (tempEl) {
        tempEl.dataset.msgId = data.message_id;
        const statusEl = tempEl.querySelector('.bubble-status');
        if (statusEl) statusEl.innerHTML = _tickIcon('sent');
        const timeEl = tempEl.querySelector('.bubble-time');
        if (timeEl) timeEl.textContent = 'just now';
      }

      Toast.success('Message sent');

    } catch (e) {
      // Remove optimistic message on failure
      $(`[data-msg-id="${tempId}"]`)?.remove();
      if (textarea) textarea.value = message; // Restore text
      Toast.error('Send failed: ' + e.message);
    } finally {
      state.isSendingMessage = false;
      Loader.set(sendBtn, false);
      textarea.disabled = false;
      textarea?.focus();
    }
  };

  // ============================================================
  // LEAD DETAILS DRAWER
  // ============================================================
  const openLeadDetails = async (leadId) => {
    Drawer.open({ title: 'Lead Intelligence', body: `<div class="empty-state"><div class="spinner"></div></div>` });

    try {
      const data = await apiGet(`/api/get_lead_details.php?lead_id=${leadId}`);
      Drawer.setContent(_leadDetailsHTML(data));
      _bindDrawerActions(data);
    } catch (e) {
      Drawer.setContent(`<div class="empty-state"><div class="empty-icon">⚠</div><div class="empty-title">Failed to load</div></div>`);
    }
  };

  const _leadDetailsHTML = (data) => {
    const lead = data.lead;
    const ana  = data.analytics || {};
    const intel= data.intelligence || {};
    const stars = '★'.repeat(Math.round(lead.rating || 0)) + '☆'.repeat(5 - Math.round(lead.rating || 0));

    return `
      <div class="detail-section">
        <div class="detail-section-title">Business Profile</div>
        <div class="detail-row"><span class="detail-key">Name</span><span class="detail-val">${escHtml(lead.business_name)}</span></div>
        <div class="detail-row"><span class="detail-key">Phone</span><span class="detail-val">${escHtml(lead.phone_display || lead.phone_number)}</span></div>
        <div class="detail-row"><span class="detail-key">Location</span><span class="detail-val">${escHtml([lead.locality, lead.city, lead.state].filter(Boolean).join(', '))}</span></div>
        ${lead.rating ? `<div class="detail-row"><span class="detail-key">Rating</span><span class="detail-val"><span class="stars">${stars}</span> ${lead.rating} (${lead.review_count} reviews)</span></div>` : ''}
        ${lead.website_url ? `<div class="detail-row"><span class="detail-key">Website</span><span class="detail-val"><a href="${escHtml(lead.website_url)}" target="_blank" style="color:var(--indigo-light)">${escHtml(lead.website_url)}</a></span></div>` : ''}
      </div>

      <div class="detail-section">
        <div class="detail-section-title">Outreach Status</div>
        <div class="detail-row"><span class="detail-key">WhatsApp</span><span class="detail-val">${Badge.waStatus(lead.whatsapp_status)}</span></div>
        <div class="detail-row"><span class="detail-key">Outreach</span><span class="detail-val">${Badge.outreachStatus(lead.outreach_status)}</span></div>
        <div class="detail-row"><span class="detail-key">Website</span><span class="detail-val">${Badge.website(lead.website_status)}</span></div>
        <div class="detail-row"><span class="detail-key">Engagement</span><span class="detail-val">${Badge.engagement(ana.engagement_score)}</span></div>
        ${lead.last_contacted_at ? `<div class="detail-row"><span class="detail-key">Last Contact</span><span class="detail-val">${escHtml(lead.last_contacted_ago || '')}</span></div>` : ''}
        ${lead.replied_at ? `<div class="detail-row"><span class="detail-key">Replied</span><span class="detail-val">${escHtml(lead.replied_ago || '')}</span></div>` : ''}
      </div>

      ${lead.generated_message ? `
      <div class="detail-section">
        <div class="detail-section-title">AI Generated Message</div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px;font-size:12px;color:var(--text-secondary);line-height:1.7;white-space:pre-wrap">${escHtml(lead.generated_message)}</div>
        <div style="margin-top:8px;display:flex;gap:8px">
          <button class="btn btn-sm btn-secondary" onclick="copyToClipboard('${escHtml(lead.generated_message?.replace(/'/g, "\\'")||"")}')">Copy Message</button>
          <button class="btn btn-sm btn-ghost" data-action="regen-message" data-lead-id="${lead.id}">Regenerate ↺</button>
        </div>
      </div>` : `
      <div class="detail-section">
        <button class="btn btn-primary btn-sm" style="width:100%" data-action="gen-message" data-lead-id="${lead.id}">✦ Generate AI Message</button>
      </div>`}

      <div class="detail-section">
        <div class="detail-section-title">Quick Actions</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-sm btn-secondary" data-action="validate-single" data-lead-id="${lead.id}">Validate WA</button>
          <button class="btn btn-sm ${lead.is_pinned ? 'btn-secondary' : 'btn-ghost'}" data-action="toggle-pin" data-lead-id="${lead.id}">${lead.is_pinned ? '📌 Unpin' : 'Pin'}</button>
          <button class="btn btn-sm btn-danger" data-action="delete-lead" data-lead-id="${lead.id}">Archive</button>
        </div>
      </div>

      <div class="detail-section">
        <div class="detail-section-title">Notes</div>
        <textarea class="form-textarea" id="lead-notes-textarea" style="min-height:80px" placeholder="Add notes about this lead…">${escHtml(lead.notes || '')}</textarea>
        <button class="btn btn-sm btn-secondary" style="margin-top:6px" data-action="save-notes" data-lead-id="${lead.id}">Save Notes</button>
      </div>

      <div class="detail-section">
        <div class="detail-section-title">Activity Timeline</div>
        <div class="timeline">
          ${(data.timeline || []).slice(0, 8).map(ev => `
            <div class="timeline-item">
              <div class="timeline-dot ${ev.color || 'indigo'}"></div>
              <div class="timeline-label">${escHtml(ev.label)}</div>
              <div class="timeline-time">${escHtml(ev.time_ago)}</div>
            </div>`).join('')}
        </div>
      </div>`;
  };

  const _bindDrawerActions = (data) => {
    const drawer = document.querySelector('.drawer');
    if (!drawer) return;

    drawer.querySelectorAll('[data-action]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const action = btn.dataset.action;
        const leadId = parseInt(btn.dataset.leadId);
        await _handleDrawerAction(action, leadId, data);
      });
    });
  };

  const _handleDrawerAction = async (action, leadId, data) => {
    switch (action) {
      case 'gen-message':
      case 'regen-message': {
        Loader.setBtn(`[data-action="${action}"]`, true);
        try {
          const res = await apiPost('/api/generate_message.php', { mode: 'single', lead_id: leadId, force_regenerate: action === 'regen-message' });
          Toast.success('AI message generated ✦');
          openLeadDetails(leadId); // Refresh drawer
        } catch (e) { Toast.error(e.message); }
        break;
      }
      case 'validate-single': {
        Loader.setBtn('[data-action="validate-single"]', true);
        try {
          const res = await apiPost('/api/validate_number.php', { lead_id: leadId });
          Toast.success(`WhatsApp: ${res.status}`);
          openLeadDetails(leadId);
        } catch (e) { Toast.error(e.message); }
        break;
      }
      case 'toggle-pin': {
        try {
          const res = await apiPost('/api/mark_read.php', { action: 'toggle_pin', lead_id: leadId });
          Toast.success(res.pinned ? 'Lead pinned' : 'Lead unpinned');
          loadLeads(true);
        } catch (e) { Toast.error(e.message); }
        break;
      }
      case 'delete-lead': {
        Modal.confirm('Archive this lead? It will be hidden from the list.', async () => {
          try {
            await apiPost('/api/mark_read.php', { action: 'delete_lead', lead_id: leadId });
            Toast.success('Lead archived');
            Drawer.close();
            loadLeads(true);
          } catch (e) { Toast.error(e.message); }
        }, { danger: true, confirmText: 'Archive' });
        break;
      }
      case 'save-notes': {
        const notes = $('#lead-notes-textarea')?.value || '';
        try {
          await apiPost('/api/mark_read.php', { action: 'update_notes', lead_id: leadId, notes });
          Toast.success('Notes saved');
        } catch (e) { Toast.error(e.message); }
        break;
      }
    }
  };


  // ============================================================
  // CAMPAIGN CONTROLS
  // ============================================================
  const startCampaign = async () => {
    const btn = $('#btn-start-campaign');
    Loader.set(btn, true);
    try {
      const res = await apiPost('/api/start_campaign.php', { campaign_id: state.campaignId });
      Toast.success(`${res.batch_result?.queued || 0} leads queued for outreach`);
      loadStats();
      loadLeads(true);
    } catch (e) { Toast.error('Start failed: ' + e.message); }
    finally { Loader.set(btn, false); }
  };

  const pauseCampaign = async () => {
    try {
      await apiPost('/api/pause_campaign.php', { action: 'pause', campaign_id: state.campaignId });
      Toast.warning('Campaign paused');
      loadStats();
    } catch (e) { Toast.error(e.message); }
  };

  const stopCampaign = async () => {
    Modal.confirm('Stop campaign and clear queue? Queued leads will reset to pending.', async () => {
      try {
        await apiPost('/api/pause_campaign.php', { action: 'stop', campaign_id: state.campaignId });
        Toast.success('Campaign stopped');
        loadStats();
        loadLeads(true);
      } catch (e) { Toast.error(e.message); }
    }, { danger: true, confirmText: 'Stop Campaign' });
  };

  // ============================================================
  // BATCH VALIDATE
  // ============================================================
  const validateBatch = async () => {
    const btn = $('#btn-validate-batch');
    Loader.set(btn, true);
    try {
      const res = await apiPost('/api/validate_batch.php', { mode: 'pending', campaign_id: state.campaignId, limit: 50 });
      Toast.info(`Validating ${res.total} numbers — results updating live`);
    } catch (e) { Toast.error(e.message); }
    finally { Loader.set(btn, false); }
  };

  // ============================================================
  // BATCH AI GENERATION
  // ============================================================
  const generateBatch = async () => {
    const btn = $('#btn-generate-batch');
    Loader.set(btn, true);
    try {
      const res = await apiPost('/api/generate_message.php', { mode: 'batch', campaign_id: state.campaignId, limit: 20, only_empty: true });
      Toast.success(`AI generated ${res.generated} messages (${res.failed} failed)`);
      loadLeads(true);
    } catch (e) { Toast.error(e.message); }
    finally { Loader.set(btn, false); }
  };

  // ============================================================
  // CSV UPLOAD
  // ============================================================
  const handleCsvUpload = async (file) => {
    if (!file) return;
    const formData = new FormData();
    formData.append('csv_file',    file);
    formData.append('campaign_id', state.campaignId);

    const btn     = $('#btn-upload-csv');
    const progress= $('#upload-progress');
    if (progress) { progress.style.display = 'block'; $('#upload-fill').style.width = '30%'; }
    Loader.set(btn, true);

    try {
      const res = await fetch('/api/upload_csv.php', {
        method:      'POST',
        body:        formData,
        credentials: 'same-origin',
        headers:     { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await res.json();

      if (progress) { $('#upload-fill').style.width = '100%'; setTimeout(() => { progress.style.display = 'none'; }, 500); }

      if (!data.success) throw new Error(data.error || 'Upload failed');

      const stats = data.stats;
      Toast.success(`✓ ${stats.imported} leads imported. ${stats.duplicates} duplicates skipped.`);
      Modal.close();
      loadStats();
      loadLeads(true);

    } catch (e) {
      Toast.error('Upload failed: ' + e.message);
      if (progress) progress.style.display = 'none';
    } finally {
      Loader.set(btn, false);
    }
  };

  // ============================================================
  // SEARCH & FILTERS
  // ============================================================
  const setupSearch = () => {
    const input = $('#search-leads');
    if (!input) return;
    input.addEventListener('input', debounce(() => {
      state.filters.search = input.value.trim();
      loadLeads(true);
    }, 350));
  };

  const setupFilters = () => {
    $$('.filter-pill[data-filter]').forEach(pill => {
      pill.addEventListener('click', () => {
        const filter = pill.dataset.filter;
        const value  = pill.dataset.value;

        // Toggle active state
        const group = $$(`[data-filter="${filter}"]`);
        group.forEach(p => p.classList.remove('active'));
        const isActive = state.filters[filter] === value;

        if (isActive) {
          state.filters[filter] = '';
        } else {
          pill.classList.add('active');
          state.filters[filter] = value;
        }

        loadLeads(true);
      });
    });
  };

  // ============================================================
  // QR MODAL
  // ============================================================
  const openQRModal = async () => {
    Modal.open({
      title: 'Scan WhatsApp QR Code',
      width: '360px',
      body: `
        <div style="text-align:center">
          <div id="qr-loading" style="padding:40px 0"><div class="spinner" style="width:24px;height:24px;margin:0 auto"></div></div>
          <div id="qr-content" style="display:none">
            <div class="qr-container">
              <img id="qr-image" src="" alt="QR Code" style="width:200px;height:200px">
              <div class="qr-instructions">Open WhatsApp → Settings → Linked Devices → Link a device → Scan this code</div>
            </div>
          </div>
          <p style="font-size:11px;color:var(--text-muted);margin-top:12px">QR auto-refreshes. Keep this window open.</p>
        </div>`,
    });

    try {
      const res = await apiGet('/api/refresh_sync.php?action=get_qr');
      const qrDiv = document.getElementById('qr-loading');
      const qrContent = document.getElementById('qr-content');
      const qrImg = document.getElementById('qr-image');
      if (qrDiv) qrDiv.style.display = 'none';
      if (qrContent) qrContent.style.display = 'block';
      if (qrImg && res.qr) qrImg.src = res.qr;
    } catch (e) {
      Toast.error('QR not available — WhatsApp may already be connected');
      Modal.close();
    }
  };

  // ============================================================
  // SOCKET EVENT LISTENERS
  // ============================================================
  const setupSocketListeners = () => {
    window.addEventListener('chat:newMessage', (e) => {
      if (state.activeLead?.id) appendMessage(e.detail);
    });

    window.addEventListener('lead:replied', () => {
      loadLeads(true);
      if (state.activeLead) {
        state.activeLead.outreach_status = 'replied';
        _renderChatHeader(state.activeLead);
      }
    });

    window.addEventListener('lead:validated', (e) => {
      const { phone, status } = e.detail;
      // Update lead in list
      const item = document.querySelector(`[data-phone="${phone}"]`);
      if (item) {
        const badge = item.querySelector('.wa-badge');
        if (badge) badge.innerHTML = Badge.waStatus(status);
      }
    });

    window.addEventListener('stats:unread', () => loadStats());
    window.addEventListener('campaign:completed', () => { loadStats(); Toast.success('🎉 Campaign complete!'); });
    window.addEventListener('outreach:started', () => loadStats());

    window.addEventListener('wa:status', (e) => {
      if (e.detail.status === 'qr_ready') {
        const qrImg = document.getElementById('qr-image');
        if (qrImg && e.detail.qr) qrImg.src = e.detail.qr;
      }
    });
  };

  // ============================================================
  // UTILITY
  // ============================================================
  const _setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };

  // ============================================================
  // INIT
  // ============================================================
  const init = async () => {
    setupSearch();
    setupFilters();
    setupSocketListeners();

    // Load initial data
    await loadStats();
    await loadLeads(true);

    // Auto-refresh stats every 60 seconds
    setInterval(() => loadStats(), 60000);

    // Event bindings
    $('#btn-start-campaign')?.addEventListener('click', startCampaign);
    $('#btn-pause-campaign')?.addEventListener('click', pauseCampaign);
    $('#btn-stop-campaign')?.addEventListener('click', stopCampaign);
    $('#btn-validate-batch')?.addEventListener('click', validateBatch);
    $('#btn-generate-batch')?.addEventListener('click', generateBatch);
    $('#btn-qr-scan')?.addEventListener('click', openQRModal);
    $('#btn-sync')?.addEventListener('click', async () => { await loadStats(); Toast.info('Synced'); });

    $('#load-more-leads')?.addEventListener('click', () => { state.page++; loadLeads(); });

    // Send message
    const sendBtn  = $('#send-message-btn');
    const textarea = $('#chat-textarea');

    sendBtn?.addEventListener('click', sendMessage);
    textarea?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    textarea?.addEventListener('input', () => autoResizeTextarea(textarea));

    // Details btn
    $('#btn-lead-details')?.addEventListener('click', () => {
      if (state.activeLead?.id) openLeadDetails(state.activeLead.id);
    });

    // CSV upload
    const csvInput = document.getElementById('csv-file-input');
    csvInput?.addEventListener('change', (e) => { if (e.target.files[0]) handleCsvUpload(e.target.files[0]); });

    // Upload zone drag-and-drop
    const uploadZone = document.getElementById('csv-upload-zone');
    if (uploadZone) {
      uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
      uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
      uploadZone.addEventListener('drop', (e) => {
        e.preventDefault(); uploadZone.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file) handleCsvUpload(file);
      });
    }

    // WA Restart
    $('#btn-restart-wa')?.addEventListener('click', async () => {
      Modal.confirm('Restart WhatsApp engine? You may need to re-scan QR.', async () => {
        try {
          await apiGet('/api/refresh_sync.php?action=restart_wa');
          Toast.info('WhatsApp restarting…');
        } catch (e) { Toast.error(e.message); }
      });
    });

    console.info('[Dashboard] Initialized');
  };

  // ── Public API ─────────────────────────────────────────────
  return {
    init,
    loadLeads,
    loadStats,
    selectLead,
    startCampaign,
    pauseCampaign,
    stopCampaign,
    validateBatch,
    generateBatch,
    openLeadDetails,
    openQRModal,
    handleCsvUpload,
    getState: () => state,
  };

})();

window.Dashboard = Dashboard;
