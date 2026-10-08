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
/* ── Monitoring scope helpers ─────────────────────────────────────
   The monitoring page is scoped by ?store=. Every poll MUST repeat that scope:
   an unscoped request answers with platform totals and overwrites the one
   store's figures on the next tick, so the widgets would silently disagree with
   the trend chart above them. The scope is read from the URL on every call
   rather than cached in a global, so a back/forward navigation or a
   data-store-change link cannot leave a stale scope behind. */
function telemetryScope() {
  const raw = new URLSearchParams(window.location.search).get('store');
  return raw && /^\d+$/.test(raw) ? raw : null;
}

function telemetryQuery() {
  const s = telemetryScope();
  return s ? '?store=' + encodeURIComponent(s) : '';
}

/* Reject a payload that answers for a DIFFERENT store than the page is showing.
   Dropping it is always safer than painting a plausible-looking wrong number;
   the next tick re-requests and recovers on its own. A payload carrying no
   store_id is accepted, for compatibility with a backend that predates scoping. */
function telemetryMatchesScope(d) {
  const s = telemetryScope();
  const want = s ? Number(s) : null;
  const got  = (d && d.store_id !== undefined && d.store_id !== null) ? d.store_id : want;
  return got === want;
}

function initTelemetry() {
  const wrap = document.getElementById('telemetry-wrap');
  if (!wrap) return;

  async function refresh() {
    const icon = document.getElementById('refresh-icon');
    if (icon) icon.classList.add('spin');
    try {
      const r = await fetch('/platform-admin/api/telemetry' + telemetryQuery(), { headers: { 'X-CSRF-Token': csrfToken() } });
      if (!r.ok) return;
      const d = await r.json();
      if (!telemetryMatchesScope(d)) return;
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
    // The typed confirmation MUST be posted. The server authorises an
    // irreversible delete with hash_equals($storeName, $req->input('confirm_text')),
    // so omitting this field made EVERY delete fail with "Type the exact store
    // name to confirm" no matter what the admin typed -- the button looked
    // broken while the store was in fact still in the database.
    if (needsConfirm) body.confirm_text = typeInput.value.trim();
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

  // One-shot guard: must never stack a second QR (double script include,
  // hot reload, re-init, etc.) — always start from a clean container.
  if (box.dataset.qrInit === '1') return;
  box.dataset.qrInit = '1';
  box.innerHTML = '';

  if (typeof QRCode === 'undefined') {
    box.innerHTML = '<p style="padding:20px;color:var(--text-3);font-size:.8rem">QR code could not load.<br>Use the key below instead.</p>';
    return;
  }

  new QRCode(box, {
    text: uri,
    width: 180,
    height: 180,
    colorDark: '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M,
  });

  // qrcodejs paints a canvas first, then (async) swaps to an <img> built
  // from it. Keep exactly ONE of the two visible and centred at all times.
  setTimeout(() => {
    const img = box.querySelector('img');
    const canvas = box.querySelector('canvas');
    if (img && img.getAttribute('src')) {
      img.style.display = 'block';
      if (canvas) canvas.style.display = 'none';
    } else if (canvas) {
      canvas.style.display = 'block';
    }
  }, 80);
}

/* ── Clipboard ──────────────────────────────────────────────────
   Single helper for every copy affordance in the portal.

   navigator.clipboard is unavailable on a plain-HTTP origin (LAN installs that
   do not terminate TLS), so every call falls back to the hidden-textarea +
   execCommand('copy') trick, and finally to a manual drag-select hint.        */
function copyText(text) {
  const write = async () => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    return false;
  };

  const legacy = () => {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  };

  return write().then(ok => ok || legacy()).catch(() => legacy());
}

/* Every [data-copy] button copies its own data-copy payload. */
function initCopyButtons() {
  document.querySelectorAll('[data-copy]').forEach(btn => {
    if (btn.dataset.copyBound === '1') return;
    btn.dataset.copyBound = '1';

    const original = btn.innerHTML;
    let timer = null;

    btn.addEventListener('click', async e => {
      e.preventDefault();
      e.stopPropagation();
      const text = btn.dataset.copy || '';

      const ok = text !== '' && await copyText(text);
      btn.classList.toggle('copied', ok);
      btn.textContent = ok ? 'Copied!' : 'Press Ctrl+C';

      clearTimeout(timer);
      timer = setTimeout(() => {
        btn.classList.remove('copied');
        btn.innerHTML = original;
      }, 2200);
    });
  });
}

/* ── TOTP secret: one-click copy ─────────────────────────────── */
function initCodeCopy() {
  const btn = document.getElementById('btn-copy-code');
  const el  = document.getElementById('flash-pairing-code');
  if (!btn || !el) return;

  const original = btn.textContent;
  btn.addEventListener('click', async () => {
    const code = el.textContent.replace(/\s+/g, '');
    let ok = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(code);
        ok = true;
      }
    } catch (_) { /* fall through to the legacy path below */ }
    if (!ok) {
      // execCommand path -- still needed on a plain-HTTP internal host, where
      // navigator.clipboard is unavailable.
      const ta = document.createElement('textarea');
      ta.value = code;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
      document.body.removeChild(ta);
    }
    btn.textContent = ok ? 'Copied!' : 'Select & copy';
    btn.classList.toggle('copied', ok);
    if (!ok) el.style.userSelect = 'all'; // let them drag-select manually
    setTimeout(() => { btn.textContent = original; btn.classList.remove('copied'); }, 2200);
  });
}
/* The whole-store code printed on the store card. Separate from
   initCodeCopy(), which targets the transient flash banner. */
function initCardCodeCopy() {
  const btn = document.getElementById('btn-copy-card-code');
  const el  = document.getElementById('card-pairing-code');
  if (!btn || !el) return;

  const original = btn.textContent;
  btn.addEventListener('click', async () => {
    const code = el.textContent.replace(/\s+/g, '');
    const ok = code !== '' && await copyText(code);
    btn.textContent = ok ? 'Copied!' : 'Press Ctrl+C';
    btn.classList.toggle('copied', ok);
    if (!ok) el.style.userSelect = 'all';   // let them drag-select manually
    setTimeout(() => { btn.textContent = original; btn.classList.remove('copied'); }, 2200);
  });
}

function initSecretCopy() {
  const btn = document.getElementById('btn-copy-secret');
  const el  = document.getElementById('totp-secret');
  if (!btn || !el) return;

  btn.addEventListener('click', async () => {
    const key = el.textContent.replace(/\s+/g, ''); // spaces are display-only
    let ok = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(key);
        ok = true;
      }
    } catch (_) { /* fall through to the legacy path below */ }
    if (!ok) {
      const ta = document.createElement('textarea');
      ta.value = key;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      document.body.appendChild(ta);
      ta.select();
      try { ok = document.execCommand('copy'); } catch (_) { ok = false; }
      document.body.removeChild(ta);
    }
    btn.textContent = ok ? '✓ Copied!' : 'Select & copy';
    btn.classList.toggle('copied', ok);
    setTimeout(() => { btn.textContent = '📋 Copy'; btn.classList.remove('copied'); }, 2200);
  });
}

/* ── Initialise ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  initTelemetry();
  initHealthPing();
  initModals();
  initUserSearch();
  initStoreSearch();
  initTotpQr();
  initSecretCopy();
  initCodeCopy();
  initCardCodeCopy();
  initCopyButtons();
});
