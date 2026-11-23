// CRM LI Projetos: small behaviours (CSP: script-src 'self', so nothing inline)
document.addEventListener('keydown', (e) => {
  // "/" focuses the client search, like most tools
  if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
    const s = document.getElementById('globalSearch');
    if (s) { e.preventDefault(); s.focus(); }
  }
});
document.querySelectorAll('[data-pw-toggle]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.getAttribute('aria-controls'));
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
  });
});
// Avatar preview on the profile page
const av = document.getElementById('avatarInput');
if (av) av.addEventListener('change', () => {
  const f = av.files && av.files[0];
  const img = document.getElementById('avatarPreview');
  if (f && img && f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.hidden = false; const ini = document.getElementById('avatarInitials'); if (ini) ini.hidden = true; }
});
// Theme picker saves on change
document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

// Pipeline board: drag a deal to another stage (POST with the page's CSRF token)
const board = document.querySelector('.board');
if (board) {
  let dragged = null;
  const msg = document.getElementById('boardMsg');
  const dialog = document.getElementById('lostDialog');
  const askReason = () => new Promise((resolve) => {
    const input = document.getElementById('lostReason');
    input.value = '';
    dialog.showModal();
    dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok' && input.value.trim() ? input.value.trim() : null), { once: true });
  });
  const recount = () => board.querySelectorAll('.col-stage').forEach((col) => {
    const n = col.querySelectorAll('.deal').length;
    col.querySelector('.n').textContent = n;
    const empty = col.querySelector('.empty');
    if (empty) empty.hidden = n > 0;
  });
  board.addEventListener('dragstart', (e) => {
    dragged = e.target.closest('.deal');
    if (!dragged) return;
    dragged.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragged.dataset.id);
  });
  board.addEventListener('dragend', () => { dragged?.classList.remove('dragging'); board.querySelectorAll('.over').forEach((z) => z.classList.remove('over')); });
  board.querySelectorAll('[data-drop]').forEach((zone) => {
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('over'));
    zone.addEventListener('drop', async (e) => {
      e.preventDefault();
      zone.classList.remove('over');
      if (!dragged) return;
      const from = dragged.closest('[data-drop]');
      const stage = zone.closest('.col-stage').dataset.stage;
      if (from === zone) return;
      const body = new FormData();
      body.append('_csrf', board.dataset.csrf);
      body.append('stage', stage);
      if (stage === 'perdido' && dialog) {
        const reason = await askReason();
        if (!reason) return;
        body.append('lost_reason', reason);
      }
      zone.prepend(dragged);
      recount();
      const url = (board.dataset.url || '/oportunidades/{id}/etapa').replace('{id}', dragged.dataset.id);
      const res = await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
      if (!res.ok) {
        from.prepend(dragged);
        recount();
        const data = await res.json().catch(() => ({}));
        msg.textContent = data.error || 'Não foi possível mover. Recarregue a página e tente de novo.';
        msg.classList.remove('d-none');
      } else msg.classList.add('d-none');
    });
  });
}

// Segmented radio buttons (activity type)
document.querySelectorAll('.seg-radio input').forEach((r) => {
  const sync = () => r.closest('.seg').querySelectorAll('.seg-radio').forEach((l) => l.toggleAttribute('aria-current', l.querySelector('input').checked));
  r.addEventListener('change', sync); sync();
});

// Live counters: bell and tasks badge refresh every minute while the tab is visible (GET /api/pulse).
if (document.querySelector('[data-live]')) {
  const set = (sel, n, text = String(n)) => document.querySelectorAll(`[data-live="${sel}"]`).forEach((el) => { el.textContent = text; el.hidden = !n; });
  const base = document.title.replace(/^\(\d+\+?\) /, '');
  const pulse = async () => {
    if (document.hidden) return;
    try {
      const res = await fetch('/api/pulse', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!res.ok || !(res.headers.get('content-type') || '').includes('json')) return;
      const d = await res.json();
      set('unread', d.unread); set('unread-dot', d.unread, d.unread > 9 ? '9+' : String(d.unread));
      const t = d.late + d.today; set('tasks', t); if ('wa' in d) set('wa', d.wa);
      document.querySelectorAll('[data-live="tasks"]').forEach((el) => el.classList.toggle('count-late', d.late > 0));
      document.title = (d.unread ? `(${d.unread}) ` : '') + base;
    } catch { /* offline: try again next minute */ }
  };
  setInterval(pulse, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pulse(); });
}

// Approval link field: select all on focus
document.querySelectorAll(".deliv-link").forEach((i) => i.addEventListener("focus", () => i.select()));

// Brand colour swatches (CSP forbids style attributes; setting el.style from JS is fine)
document.querySelectorAll("[data-color]").forEach((el) => { el.style.background = el.dataset.color; });

// Installable app (PWA): service worker + "Instalar o app" (Android/desktop prompt, iPhone instructions)
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
(() => {
  const btn = document.querySelector('[data-install]');
  if (!btn) return;
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  if (standalone) return;
  let deferred = null;
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; btn.hidden = false; });
  window.addEventListener('appinstalled', () => { btn.hidden = true; deferred = null; });
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (ios) btn.hidden = false;
  btn.addEventListener('click', async () => {
    if (deferred) { deferred.prompt(); await deferred.userChoice; deferred = null; btn.hidden = true; }
    else if (ios) document.getElementById('iosInstall')?.showModal();
  });
})();
// Client form: fill the address from the CEP (only empty fields, so nothing typed is overwritten)
const zip = document.getElementById('c-zip');
if (zip) zip.addEventListener('input', async () => {
  const cep = zip.value.replace(/\D/g, '');
  if (cep.length !== 8 || zip.dataset.last === cep) return;
  zip.dataset.last = cep;
  try {
    const r = await fetch('/api/cep/' + cep, { headers: { Accept: 'application/json' } });
    if (!r.ok) return;
    const a = await r.json();
    const fill = (id, v) => { const el = document.getElementById(id); if (el && v && !el.value.trim()) el.value = v; };
    fill('c-street', a.street); fill('c-district', a.district); fill('c-city', a.city); fill('c-uf', a.state);
    if (a.street) document.getElementById('c-num')?.focus();
  } catch (_) { /* offline or ViaCEP down: the user types it */ }
});

// WhatsApp conversation: scroll to the newest, Enter sends, textarea grows, new messages every 10 s
(() => {
  const box = document.getElementById('wa-msgs');
  if (!box) return;
  const end = () => { box.scrollTop = box.scrollHeight; };
  end();
  const ta = document.getElementById('wa-text');
  if (ta) {
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; };
    ta.addEventListener('input', grow);
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (ta.value.trim()) ta.form.requestSubmit(); } });
    ta.form.addEventListener('submit', () => { ta.form.querySelector('button[type="submit"]').disabled = true; });
    if (matchMedia('(min-width: 992px)').matches) ta.focus();
  }
  const anchor = document.getElementById('fim');
  const add = (m) => {
    const d = document.createElement('div');
    d.className = 'wa-msg' + (m.mine ? ' is-mine' : '') + (m.status === 'falhou' ? ' is-failed' : '');
    const p = document.createElement('p'); p.textContent = m.body; d.append(p);
    const s = document.createElement('span'); s.className = 'wa-meta';
    s.textContent = (m.mine && m.by ? m.by.split(' ')[0] + ' · ' : '') + m.time + (m.status === 'falhou' ? ' · não enviada' : '');
    d.append(s);
    box.querySelector('.wa-none')?.remove();
    box.insertBefore(d, anchor);
  };
  const poll = async () => {
    if (document.hidden) return;
    try {
      const res = await fetch(`${box.dataset.poll}?depois=${box.dataset.last}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!res.ok) return;
      const { messages } = await res.json();
      if (!messages.length) return;
      const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
      messages.forEach(add);
      box.dataset.last = messages[messages.length - 1].id;
      if (atBottom) end();
    } catch { /* offline: next tick */ }
  };
  setInterval(poll, 10000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
})();
