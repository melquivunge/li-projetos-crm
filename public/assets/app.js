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
      const t = d.late + d.today; set('tasks', t);
      document.querySelectorAll('[data-live="tasks"]').forEach((el) => el.classList.toggle('count-late', d.late > 0));
      document.title = (d.unread ? `(${d.unread}) ` : '') + base;
    } catch { /* offline: try again next minute */ }
  };
  setInterval(pulse, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) pulse(); });
}

// Approval link field: select all on focus
document.querySelectorAll(".deliv-link").forEach((i) => i.addEventListener("focus", () => i.select()));
