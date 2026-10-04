/* ProCast Platform Admin — admin.js */
'use strict';

/* ── CSRF helper ──────────────────────────────────────────────── */
function csrfToken() {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.content : '';
}

async function apiPost(url, body = {}) {
  const r = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': csrfToken(),
    },
    body: new URLSearchParams({ ...body, csrf: csrfToken() }),
  });
  return r;
}

/* ── Telemetry auto-refresh ──────────────────────────────────── */
function initTelemetry() {
  const wrap = document.getElementById('telemetry-wrap');
  if (!wrap) return;

  async function refresh() {
    const icon = document.getElementById('refresh-icon');
    if (icon) icon.classList.add('spin');
    try {
      const r = await fetch('/platform-admin/api/telemetry', { headers: { 'X-CSRF-Token': csrfToken() } });
      if (!r.ok) return;
      const d = await r.json();
      update('stat-total',    d.counts?.total            ?? '—');
      update('stat-active',   d.counts?.active           ?? '—');
      update('stat-pending',  d.counts?.pending_approval ?? '—');
      update('stat-suspended',d.counts?.suspended        ?? '—');
      update('stat-sessions', d.sessions                 ?? '—');
      update('stat-today-count', d.volume?.today?.count  ?? '—');
      update('stat-today-total', '₱' + fmt(d.volume?.today?.total  ?? 0));
      update('stat-month-count', d.volume?.month?.count  ?? '—');
      update('stat-month-total', '₱' + fmt(d.volume?.month?.total  ?? 0));
      const ts = document.getElementById('refresh-ts');
      if (ts) ts.textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch (_) {}
    if (icon) icon.classList.remove('spin');
  }

  function update(id, val) {
    const el = document.getElementById(id);
    if (el) { el.textContent = val; el.dataset.loaded = '1'; }
  }

  function fmt(n) {
    return Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  refresh();
  setInterval(refresh, 30000);

  const btn = document.getElementById('btn-refresh');
  if (btn) btn.addEventListener('click', refresh);
}

/* ── ML health ping ──────────────────────────────────────────── */
function initHealthPing() {
  const dot   = document.getElementById('health-dot');
  const name  = document.getElementById('health-status-text');
  const latEl = document.getElementById('health-latency');
  if (!dot) return;

  async function ping() {
    try {
      const t0 = Date.now();
      const r = await fetch('/platform-admin/api/health', { headers: { 'X-CSRF-Token': csrfToken() } });
      const ms = Date.now() - t0;
      const d = await r.json();
      const up = d.up === true;
      dot.className = 'health-dot ' + (up ? 'up' : 'down');
      if (name)  name.textContent  = up ? 'Forecasting Engine — Online' : 'Forecasting Engine — Offline';
      if (latEl) latEl.textContent = ms + 'ms';
    } catch (_) {
      dot.className = 'health-dot unknown';
      if (name) name.textContent = 'Forecasting Engine — Unreachable';
    }
  }

  ping();
  setInterval(ping, 60000);
}

/* ── Confirmation modals ─────────────────────────────────────── */
function initModals() {
  document.querySelectorAll('[data-confirm]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const cfg = JSON.parse(btn.dataset.confirm);
      openModal(cfg, btn);
    });
  });
}

function openModal(cfg, trigger) {
  const bd = document.getElementById('modal-backdrop');
  if (!bd) return;

  bd.querySelector('.modal-title').textContent = cfg.title || 'Confirm action';
  bd.querySelector('.modal-desc').textContent  = cfg.desc  || '';

  const typeWrap = bd.querySelector('#modal-confirm-wrap');
  const typeInput = bd.querySelector('#modal-confirm-input');
  const typeLabel = bd.querySelector('#modal-confirm-label-text');
  const needsConfirm = !!cfg.confirmText;
  typeWrap.style.display = needsConfirm ? 'block' : 'none';
  if (needsConfirm) {
    typeLabel.textContent = `Type "${cfg.confirmText}" to confirm:`;
    typeInput.value = '';
    typeInput.dataset.expected = cfg.confirmText;
  }

  const reasonWrap = bd.querySelector('#modal-reason-wrap');
  const reasonInput = bd.querySelector('#modal-reason-input');
  const needsReason = !!cfg.requireReason;
  reasonWrap.style.display = needsReason ? 'block' : 'none';
  if (needsReason) reasonInput.value = '';

  const submitBtn = bd.querySelector('#modal-submit');
  submitBtn.className = 'btn ' + (cfg.btnClass || 'btn-rose');
  submitBtn.textContent = cfg.btnLabel || 'Confirm';

  bd.classList.add('show');
  if (needsConfirm) typeInput.focus();
  else if (needsReason) reasonInput.focus();
  else submitBtn.focus();

  submitBtn.onclick = async () => {
    if (needsConfirm) {
      const expected = typeInput.dataset.expected || '';
      if (typeInput.value.trim() !== expected) {
        typeInput.classList.add('is-invalid');
        typeInput.focus();
        return;
      }
      typeInput.classList.remove('is-invalid');
    }
    const reason = needsReason ? reasonInput.value.trim() : '';
    if (needsReason && !reason) {
      reasonInput.classList.add('is-invalid');
      reasonInput.focus();
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Processing…';

    const body = {};
    if (reason) body.reason = reason;
    const r = await apiPost(cfg.action, body);
    if (r.ok || r.redirected) {
      window.location.href = cfg.redirect || cfg.action.replace(/\/(approve|reject|suspend|reactivate)$/, '');
    } else {
      submitBtn.disabled = false;
      submitBtn.textContent = cfg.btnLabel || 'Confirm';
      alert('Action failed (HTTP ' + r.status + '). Please try again.');
    }
  };

  bd.querySelector('#modal-cancel').onclick = closeModal;
  bd.addEventListener('click', e => { if (e.target === bd) closeModal(); });
}

function closeModal() {
  document.getElementById('modal-backdrop')?.classList.remove('show');
}

/* ── User search (live AJAX-like via form submission) ────────── */
function initUserSearch() {
  const form = document.getElementById('user-search-form');
  if (!form) return;
  let t;
  form.querySelector('[name="q"]')?.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => form.submit(), 600);
  });
}

/* ── Store search ────────────────────────────────────────────── */
function initStoreSearch() {
  const form = document.getElementById('store-search-form');
  if (!form) return;
  let t;
  form.querySelector('[name="q"]')?.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => form.submit(), 600);
  });
}

/* ── TOTP QR code (enrollment flow) ─────────────────────────── */
function initTotpQr() {
  const box = document.getElementById('qr-box');
  const uri = document.getElementById('totp-otpauth')?.value;
  if (!box || !uri) return;

  if (typeof QRCode !== 'undefined') {
    new QRCode(box, {
      text: uri,
      width: 180,
      height: 180,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M,
    });
    // Style the generated image
    setTimeout(() => {
      const img = box.querySelector('img');
      if (img) {
        img.style.borderRadius = '8px';
        img.style.display = 'block';
        img.style.margin = '0 auto';
      }
    }, 50);
  } else {
    // Fallback: show open-in-app link prominently
    box.innerHTML = '<p style="padding:20px;color:var(--text-3);font-size:.8rem">QR code could not load.<br>Use the button below instead.</p>';
  }
}

/* ── Initialise ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initTelemetry();
  initHealthPing();
  initModals();
  initUserSearch();
  initStoreSearch();
  initTotpQr();
});
