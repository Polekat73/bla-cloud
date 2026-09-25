/* BLA-Cloud front-end. No frameworks, no inline scripts (strict CSP). */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const scriptBase = (document.currentScript && document.currentScript.src.split('/js/app.js')[0]) || 'assets';

  // ---------- Mobile nav ----------
  $$('[data-toggle-nav]').forEach((b) => b.addEventListener('click', () => document.body.classList.toggle('nav-open')));

  // ---------- Busy state on submit ----------
  document.addEventListener('submit', (ev) => {
    const btn = ev.submitter || $('[type=submit]', ev.target);
    if (btn && btn.dataset.busy) {
      btn.dataset.label = btn.innerHTML;
      btn.textContent = btn.dataset.busy;
      btn.classList.add('is-busy');
    }
  });

  // ---------- Password strength ----------
  function strength(pw) {
    if (!pw) return 0;
    let pool = 0;
    if (/[a-z]/.test(pw)) pool += 26;
    if (/[A-Z]/.test(pw)) pool += 26;
    if (/\d/.test(pw)) pool += 10;
    if (/[^A-Za-z0-9]/.test(pw)) pool += 33;
    const unique = new Set(pw).size;
    const bits = Math.log2(Math.max(pool, 1)) * Math.min(pw.length, unique * 2);
    return Math.max(0, Math.min(1, bits / 80));
  }
  $$('[data-strength]').forEach((input) => {
    const meter = input.closest('form').querySelector('[data-meter] span');
    if (!meter) return;
    input.addEventListener('input', () => {
      const s = input.value.length < 12 ? Math.min(0.3, strength(input.value)) : strength(input.value);
      meter.style.width = Math.round(s * 100) + '%';
      meter.style.background = s < 0.45 ? 'var(--danger)' : s < 0.7 ? 'var(--warn)' : 'var(--emerald)';
    });
  });

  // ---------- Setup: database choice ----------
  const dbForm = $('[data-db-form]');
  if (dbForm) {
    const fields = $('[data-mysql-fields]', dbForm);
    const sync = () => { fields.hidden = $('input[name=driver]:checked', dbForm)?.value !== 'mysql'; };
    $$('input[name=driver]', dbForm).forEach((r) => r.addEventListener('change', sync));
    sync();
  }

  // ---------- QR code (2FA setup) ----------
  const qrEl = $('[data-qr]');
  if (qrEl) {
    const s = document.createElement('script');
    s.src = scriptBase + '/vendor/qrcode.js';
    s.onload = () => {
      /* global qrcode */
      const qr = qrcode(0, 'M');
      qr.addData(qrEl.dataset.qr);
      qr.make();
      qrEl.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    };
    document.head.appendChild(s);
  }

  // ---------- Copy / download / print ----------
  function toast(btn, text) {
    const old = btn.innerHTML;
    btn.textContent = text;
    setTimeout(() => { btn.innerHTML = old; }, 1600);
  }
  $$('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); toast(b, 'Copied'); }
    catch { toast(b, 'Copy failed'); }
  }));
  $$('[data-download-codes]').forEach((b) => b.addEventListener('click', () => {
    const codes = $$('[data-codes] code').map((c) => c.textContent.trim());
    const text = 'BLA-Cloud recovery codes\nCreated: ' + new Date().toLocaleString()
      + '\nEach code can be used once if you lose access to your authenticator app.\n\n' + codes.join('\n') + '\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    a.download = b.dataset.filename || 'recovery-codes.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }));
  $$('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  $$('[data-enable-next]').forEach((cb) => {
    const next = cb.closest('form').querySelector('[data-next]');
    cb.addEventListener('change', () => { next.disabled = !cb.checked; });
  });

  // ---------- Dialogs ----------
  $$('[data-open]').forEach((b) => b.addEventListener('click', () => {
    const d = document.getElementById(b.dataset.open);
    d.showModal();
    const first = $('input:not([type=hidden])', d);
    if (first) first.focus();
  }));
  $$('dialog [data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));


  // Confirm dangerous buttons
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-confirm]');
    if (b && !window.confirm(b.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // Quota bars, select-on-focus, admin forms
  $$('[data-width]').forEach((el) => { el.style.width = Math.max(2, Math.min(100, +el.dataset.width)) + '%'; });
  // CSP forbids inline style="..." attributes, so dynamic colors (calendar chips, legend dots) are set here instead.
  $$('[data-chip-color]').forEach((el) => el.style.setProperty('--cal-color', el.dataset.chipColor));
  $$('[data-dot-color]').forEach((el) => { el.style.background = el.dataset.dotColor; });
  $$('[data-select-on-focus]').forEach((el) => el.addEventListener('focus', () => el.select()));
  const addUser = $('[data-adduser]');
  if (addUser) {
    const pf = $('[data-password-field]', addUser);
    const meter = $('[data-meter]', addUser);
    const sync = () => {
      const pw = $('input[name=mode]:checked', addUser)?.value === 'password';
      pf.hidden = !pw; meter.hidden = !pw; $('input', pf).required = pw;
    };
    $$('input[name=mode]', addUser).forEach((r) => r.addEventListener('change', sync));
    sync();
  }
  const smtp = $('[data-smtp-fields]');
  if (smtp) {
    const sync = () => { smtp.hidden = $('input[name=mail_mode]:checked')?.value !== 'smtp'; };
    $$('input[name=mail_mode]').forEach((r) => r.addEventListener('change', sync));
    sync();
  }

  // ---------- Selection (files & trash) ----------
  function initSelection(root, valueName) {
    const bar = $('[data-selection-bar]', root);
    if (!bar) return { selected: () => [] };
    const countEl = $('[data-selection-count]', bar);
    const inputs = $('[data-selection-inputs]', bar);
    const boxes = () => $$('[data-select]', root);
    const selected = () => boxes().filter((b) => b.checked);
    function refresh() {
      const s = selected();
      bar.hidden = s.length === 0;
      countEl.textContent = s.length + ' selected';
      boxes().forEach((b) => b.closest('tr').classList.toggle('is-selected', b.checked));
      const all = $('[data-select-all]', root);
      if (all) { all.checked = s.length > 0 && s.length === boxes().length; all.indeterminate = s.length > 0 && s.length < boxes().length; }
      inputs.textContent = '';
      s.forEach((b) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = valueName; i.value = b.value;
        inputs.appendChild(i);
      });
    }
    root.addEventListener('change', (e) => {
      if (e.target.matches('[data-select-all]')) boxes().forEach((b) => { b.checked = e.target.checked; });
      if (e.target.matches('[data-select], [data-select-all]')) refresh();
    });
    $('[data-clear-selection]', bar)?.addEventListener('click', () => { boxes().forEach((b) => { b.checked = false; }); refresh(); });
    return { selected, refresh };
  }

  const trashRoot = $('[data-trash]');
  if (trashRoot) initSelection(trashRoot, 'ids[]');

  // ---------- Calendar ----------
  const calRoot = $('[data-calendar]');
  if (calRoot) initCalendar(calRoot);

  function initCalendar(root) {
    const dlg = document.getElementById('dlg-event');
    const form = $('[data-event-form]', dlg);
    const titleEl = $('[data-event-title]', dlg);
    const deleteBtn = $('[data-delete-event]', dlg);
    const deleteForm = document.getElementById('form-delete-event');
    const allDayBox = $('[data-f=all_day]', form);
    const timeFields = $$('[data-time-field]', dlg);
    const f = (name) => $('[data-f=' + name + ']', form);

    function syncTimeFields() { timeFields.forEach((el) => { el.hidden = allDayBox.checked; }); }
    allDayBox.addEventListener('change', syncTimeFields);

    function resetForm(dateStr) {
      form.reset();
      f('object_id').value = '';
      if (dateStr) { f('date').value = dateStr; f('end_date').value = dateStr; }
      titleEl.textContent = 'New event';
      deleteBtn.hidden = true;
      syncTimeFields();
    }
    function fillForm(ds) {
      f('object_id').value = ds.objectId;
      f('calendar_id').value = ds.calendarId;
      f('title').value = ds.title;
      allDayBox.checked = ds.allDay === '1';
      f('date').value = ds.date;
      f('start_time').value = ds.startTime;
      f('end_date').value = ds.endDate;
      f('end_time').value = ds.endTime;
      f('location').value = ds.location;
      f('description').value = ds.description;
      f('remind').value = ds.remind || '';
      titleEl.textContent = 'Edit event';
      deleteBtn.hidden = false;
      syncTimeFields();
    }

    const newBtn = $('[data-new-event]');
    if (newBtn) newBtn.addEventListener('click', () => resetForm(newBtn.dataset.defaultDate));

    root.addEventListener('click', (e) => {
      const addHere = e.target.closest('[data-add-here]');
      if (addHere) {
        resetForm(addHere.closest('[data-day]')?.dataset.date);
        dlg.showModal();
        return;
      }
      const chip = e.target.closest('[data-event]');
      if (chip) { fillForm(chip.dataset); dlg.showModal(); }
    });

    deleteBtn.addEventListener('click', () => {
      if (!window.confirm('Delete this event?')) return;
      $('[data-df=calendar_id]', deleteForm).value = f('calendar_id').value;
      $('[data-df=object_id]', deleteForm).value = f('object_id').value;
      deleteForm.submit();
    });
  }

  // ---------- Contacts ----------
  const contactsRoot = $('[data-contacts]');
  if (contactsRoot) initContacts(contactsRoot);

  function initContacts(root) {
    const dlg = document.getElementById('dlg-contact');
    const form = $('[data-contact-form]', dlg);
    const titleEl = $('[data-contact-title]', dlg);
    const deleteBtn = $('[data-delete-contact]', dlg);
    const deleteForm = document.getElementById('form-delete-contact');
    const photoPreview = $('[data-photo-preview]', dlg);
    const photoPlaceholder = $('[data-photo-placeholder]', dlg);
    const removeRow = $('[data-remove-photo-row]', dlg);
    const photoInput = $('[data-photo-input]', dlg);
    const f = (name) => $('[data-f=' + name + ']', form);

    function setPhoto(src) {
      if (src) { photoPreview.src = src; photoPreview.hidden = false; photoPlaceholder.hidden = true; removeRow.hidden = false; }
      else { photoPreview.hidden = true; photoPreview.removeAttribute('src'); photoPlaceholder.hidden = false; removeRow.hidden = true; }
    }
    photoInput.addEventListener('change', () => {
      const file = photoInput.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => setPhoto(reader.result);
      reader.readAsDataURL(file);
    });

    function fillRows(selector, list) {
      $$(selector, form).forEach((row, i) => {
        const item = list[i] || { type: '', value: '' };
        const sel = $('select', row);
        sel.value = item.type || sel.options[0].value;
        $('input', row).value = item.value || '';
      });
    }

    function resetForm() {
      form.reset();
      f('id').value = '';
      titleEl.textContent = 'New contact';
      deleteBtn.hidden = true;
      setPhoto(null);
      fillRows('[data-phone-rows] > div', []);
      fillRows('[data-email-rows] > div', []);
    }
    function fillForm(ds) {
      f('id').value = ds.id;
      f('given').value = ds.given || '';
      f('family').value = ds.family || '';
      f('note').value = ds.note || '';
      f('street').value = ds.street || '';
      f('city').value = ds.city || '';
      f('region').value = ds.region || '';
      f('postal').value = ds.postal || '';
      f('country').value = ds.country || '';
      setPhoto(ds.photo || null);
      fillRows('[data-phone-rows] > div', JSON.parse(ds.phones || '[]'));
      fillRows('[data-email-rows] > div', JSON.parse(ds.emails || '[]'));
      titleEl.textContent = 'Edit contact';
      deleteBtn.hidden = false;
    }

    const newBtn = $('[data-new-contact]');
    if (newBtn) newBtn.addEventListener('click', resetForm);

    root.addEventListener('click', (e) => {
      const row = e.target.closest('[data-contact]');
      if (row) { fillForm(row.dataset); dlg.showModal(); }
    });

    deleteBtn.addEventListener('click', () => {
      if (!window.confirm('Delete this contact?')) return;
      $('[data-df=id]', deleteForm).value = f('id').value;
      deleteForm.submit();
    });
  }

  // ---------- Files ----------
  const files = $('[data-files]');
  if (files) initFiles(files);

  function initFiles(root) {
    const csrf = document.body.dataset.csrf;
    const ds = root.dataset;
    const dir = ds.dir;
    const chunkSize = parseInt(ds.chunk, 10) || 2 * 1024 * 1024;
    const q = (url, params) => url + (url.includes('?') ? '&' : '?') + new URLSearchParams(params).toString();
    const itemOf = (el) => el.closest('[data-path]');

    // Layout (list / grid), remembered per browser
    let layout = 'list';
    try { layout = localStorage.getItem('bla.layout') || 'list'; } catch { /* storage blocked */ }
    function setLayout(l) {
      layout = l; root.dataset.layout = l;
      $$('[data-layout]', root).forEach((b) => b.classList.toggle('is-on', b.dataset.layout === l));
      try { localStorage.setItem('bla.layout', l); } catch { /* ignore */ }
    }
    $$('[data-layout]', root).forEach((b) => b.addEventListener('click', () => setLayout(b.dataset.layout)));
    setLayout(layout);

    // Thumbnails that fail (e.g. unsupported image) fall back quietly
    $$('img[data-thumb]', root).forEach((img) => img.addEventListener('error', () => { img.closest('.ficon').classList.remove('ficon--thumb'); img.remove(); }));

    const sel = initSelection(root, 'targets[]');

    // ---- Rename / delete ----
    const dlgRename = document.getElementById('dlg-rename');
    const dlgDelete = document.getElementById('dlg-delete');
    function fillTargets(holder, paths) {
      holder.textContent = '';
      paths.forEach((p) => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'targets[]'; i.value = p;
        holder.appendChild(i);
      });
    }
    function openDelete(paths, names) {
      fillTargets($('[data-delete-targets]', dlgDelete), paths);
      $('[data-delete-summary]', dlgDelete).textContent = names.length === 1
        ? '“' + names[0] + '” will be moved to the trash.'
        : names.length + ' items will be moved to the trash.';
      dlgDelete.showModal();
    }
    root.addEventListener('click', (e) => {
      const item = itemOf(e.target);
      if (!item) return;
      if (e.target.closest('[data-rename]')) {
        $('[data-rename-target]', dlgRename).value = item.dataset.path;
        const input = $('[data-rename-name]', dlgRename);
        input.value = item.dataset.name;
        dlgRename.showModal();
        const dot = item.dataset.name.lastIndexOf('.');
        input.focus();
        input.setSelectionRange(0, dot > 0 && item.dataset.dir !== '1' ? dot : item.dataset.name.length);
      } else if (e.target.closest('[data-delete]')) {
        openDelete([item.dataset.path], [item.dataset.name]);
      } else if (e.target.closest('[data-move-one]')) {
        openMove([item.dataset.path], [item.dataset.name]);
      } else if (e.target.closest('[data-share]')) {
        openShare(item.dataset.path, item.dataset.name);
      } else if (e.target.closest('[data-versions]')) {
        openVersions(item.dataset.path, item.dataset.name);
      } else if (e.target.closest('[data-open-viewer]')) {
        e.preventDefault();
        openViewer(item.dataset.path);
      }
    });
    $('[data-delete-selected]')?.addEventListener('click', () => {
      const s = sel.selected();
      openDelete(s.map((b) => b.value), s.map((b) => b.closest('tr').dataset.name));
    });
    $$('[data-move-selected]').forEach((b) => b.addEventListener('click', () => {
      const s = sel.selected();
      openMove(s.map((x) => x.value), s.map((x) => x.closest('tr').dataset.name));
    }));
    $('[data-zip-selected]')?.addEventListener('click', () => {
      // The download starts in the background; clear the selection afterwards.
      setTimeout(() => { $$('[data-select]', root).forEach((b) => { b.checked = false; }); sel.refresh(); }, 300);
    });

    // ---- Move / copy picker ----
    const dlgMove = document.getElementById('dlg-move');
    let moveTargets = [];
    async function loadFolders(path) {
      const list = $('[data-move-list]', dlgMove);
      list.innerHTML = '<li class="muted">Loading…</li>';
      $('[data-move-dest]', dlgMove).value = path;
      try {
        const r = await fetch(q(ds.foldersUrl, { path }), { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
        const data = await r.json();
        if (!data.ok) throw new Error(data.error);
        const crumbs = $('[data-move-crumbs]', dlgMove);
        crumbs.textContent = '';
        data.crumbs.forEach((c, i) => {
          if (i) { const s = document.createElement('span'); s.className = 'crumbs__sep'; s.textContent = '›'; crumbs.appendChild(s); }
          const b = document.createElement('a');
          b.href = '#'; b.textContent = c.name;
          if (i === data.crumbs.length - 1) b.className = 'crumbs__current';
          b.addEventListener('click', (ev) => { ev.preventDefault(); loadFolders(c.path); });
          crumbs.appendChild(b);
        });
        list.textContent = '';
        if (!data.folders.length) list.innerHTML = '<li class="muted">No folders inside. You can move items right here.</li>';
        data.folders.forEach((f) => {
          const li = document.createElement('li');
          const b = document.createElement('button');
          b.type = 'button';
          b.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-folder"></use></svg>';
          b.append(document.createTextNode(f.name));
          // You can't put a folder inside itself.
          if (moveTargets.some((t) => f.path === t || f.path.startsWith(t + '/'))) b.disabled = true;
          b.addEventListener('click', () => loadFolders(f.path));
          li.appendChild(b); list.appendChild(li);
        });
        const inside = moveTargets.some((t) => path === t || path.startsWith(t + '/'));
        $('[data-move-here]', dlgMove).disabled = inside;
        $('[data-copy-here]', dlgMove).disabled = inside;
      } catch (err) {
        list.innerHTML = '';
        const li = document.createElement('li'); li.className = 'muted'; li.textContent = 'Could not load folders: ' + err.message;
        list.appendChild(li);
      }
    }
    function openMove(paths, names) {
      if (!paths.length) return;
      moveTargets = paths;
      fillTargets($('[data-move-targets]', dlgMove), paths);
      $('[data-move-title]', dlgMove).textContent = 'Move or copy';
      $('[data-move-summary]', dlgMove).textContent = (names.length === 1 ? '“' + names[0] + '”' : names.length + ' items') + ' — choose a destination folder:';
      dlgMove.showModal();
      loadFolders(dir);
    }

    // ---- Versions ----
    const dlgVersions = document.getElementById('dlg-versions');
    async function openVersions(path, name) {
      $('[data-versions-file]', dlgVersions).textContent = name;
      const box = $('[data-versions-list]', dlgVersions);
      box.innerHTML = '<p class="muted">Loading…</p>';
      if (!dlgVersions.open) dlgVersions.showModal();
      try {
        const r = await fetch(q(ds.versionsUrl, { path }), { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
        const data = await r.json();
        if (!data.ok) throw new Error(data.error);
        $('[data-versions-keep]', dlgVersions).textContent = data.keep;
        box.textContent = '';
        if (!data.versions.length) {
          box.innerHTML = '<p class="muted">No previous versions yet. Upload a file with the same name to keep the old one here.</p>';
          return;
        }
        const ul = document.createElement('ul'); ul.className = 'version-list';
        data.versions.forEach((v) => {
          const li = document.createElement('li'); li.className = 'version';
          const when = document.createElement('span'); when.className = 'version__when'; when.textContent = v.modified;
          const size = document.createElement('span'); size.className = 'version__size'; size.textContent = v.size;
          const act = document.createElement('span'); act.className = 'version__actions';
          const dl = document.createElement('a'); dl.className = 'btn btn--ghost btn--sm'; dl.href = q(ds.versionDownloadUrl, { id: v.id }); dl.textContent = 'Download';
          const rs = document.createElement('button'); rs.type = 'button'; rs.className = 'btn btn--secondary btn--sm'; rs.textContent = 'Restore';
          const del = document.createElement('button'); del.type = 'button'; del.className = 'icon-btn icon-btn--danger'; del.title = 'Delete this version';
          del.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#i-trash"></use></svg>';
          rs.addEventListener('click', () => versionAction(ds.versionRestoreUrl, v.id, rs, true));
          del.addEventListener('click', () => { if (confirm('Delete this version forever?')) versionAction(ds.versionDeleteUrl, v.id, del, false, path, name); });
          act.append(dl, rs, del);
          li.append(when, size, act); ul.appendChild(li);
        });
        box.appendChild(ul);
      } catch (err) {
        box.textContent = 'Could not load versions: ' + err.message;
      }
    }
    async function versionAction(url, id, btn, reload, path, name) {
      btn.disabled = true;
      const fd = new FormData(); fd.append('_csrf', csrf); fd.append('id', String(id)); fd.append('dir', dir);
      try {
        const r = await fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
        const data = await r.json();
        if (!data.ok) throw new Error(data.error);
        if (reload) location.reload(); else openVersions(path, name);
      } catch (err) { btn.disabled = false; alert(err.message); }
    }

    // ---- Share dialog (own files) ----
    const dlgShare = document.getElementById('dlg-share');
    let sharePath = '';
    const post = async (url, data) => {
      const fd = new FormData(); fd.append('_csrf', csrf);
      Object.entries(data).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
      const j = await r.json().catch(() => ({ ok: false, error: 'Unexpected server response.' }));
      if (!j.ok) throw new Error(j.error || 'Something went wrong.');
      return j;
    };
    function shareMsg(text, bad) {
      const m = $('[data-share-msg]', dlgShare);
      m.textContent = text; m.classList.toggle('is-bad', !!bad);
    }
    const svg = (id) => '<svg class="icon" aria-hidden="true"><use href="#i-' + id + '"></use></svg>';
    async function loadShare() {
      const r = await fetch(q(ds.shareInfoUrl, { path: sharePath }), { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
      const d = await r.json();
      if (!d.ok) { shareMsg(d.error, true); return; }
      $('[data-share-name]', dlgShare).textContent = d.name;
      $('[data-share-kind]', dlgShare).textContent = d.isDir ? 'Folder — people get everything inside it.' : 'File';
      const sel = $('[data-share-people]', dlgShare);
      sel.textContent = '';
      d.people.forEach((p) => { const o = document.createElement('option'); o.value = p.id; o.textContent = p.name + (p.name !== p.username ? ' (' + p.username + ')' : ''); sel.appendChild(o); });
      $('[data-share-user-form]', dlgShare).hidden = !d.people.length;
      $('[data-share-nopeople]', dlgShare).hidden = !!d.people.length;
      $('[data-share-links]', dlgShare).hidden = !d.links;
      $('[data-share-links-off]', dlgShare).hidden = d.links;
      const lp = $('[data-link-perms]', dlgShare);
      lp.textContent = '';
      (d.isDir ? ['view', 'upload', 'drop', 'edit'] : ['view']).forEach((k) => { const o = document.createElement('option'); o.value = k; o.textContent = d.labels[k]; lp.appendChild(o); });
      const exp = $('[data-link-expires]', dlgShare);
      exp.value = d.defaultExpiry; exp.min = new Date().toISOString().slice(0, 10); exp.required = d.maxDays > 0;
      if (d.maxDays > 0) exp.max = new Date(Date.now() + d.maxDays * 864e5).toISOString().slice(0, 10);
      $('[data-link-pass]', dlgShare).required = d.needPass;
      $('[data-link-pass-note]', dlgShare).textContent = d.needPass ? '(required)' : '(optional)';
      const list = $('[data-share-list]', dlgShare);
      list.textContent = '';
      if (!d.shares.length) { list.innerHTML = '<li class="muted">Only you.</li>'; }
      d.shares.forEach((s) => {
        const li = document.createElement('li'); li.className = 'share-item' + (s.expired ? ' is-expired' : '');
        const ic = document.createElement('span'); ic.className = 'share-item__icon'; ic.innerHTML = svg(s.type === 'user' ? 'users' : 'link');
        const body = document.createElement('div'); body.className = 'share-item__body';
        const who = document.createElement('strong'); who.textContent = s.who;
        const meta = document.createElement('span'); meta.className = 'muted';
        meta.textContent = s.permLabel + (s.password ? ' · password' : '') + (s.expires ? (s.expired ? ' · expired ' : ' · until ') + s.expires : '')
          + (s.type === 'link' ? ' · opened ' + s.opens + '×' : '');
        body.append(who, meta);
        const act = document.createElement('div'); act.className = 'share-item__actions';
        if (s.url) {
          const inp = document.createElement('input'); inp.className = 'mono share-url'; inp.readOnly = true; inp.value = s.url;
          inp.addEventListener('focus', () => inp.select());
          body.appendChild(inp);
          const cp = document.createElement('button'); cp.type = 'button'; cp.className = 'icon-btn'; cp.title = 'Copy link'; cp.innerHTML = svg('copy');
          cp.addEventListener('click', async () => { try { await navigator.clipboard.writeText(s.url); shareMsg('Link copied.'); } catch { inp.select(); shareMsg('Press Ctrl+C / Cmd+C to copy.'); } });
          act.appendChild(cp);
          if (d.mail) {
            const em = document.createElement('button'); em.type = 'button'; em.className = 'icon-btn'; em.title = 'Email this link'; em.innerHTML = svg('mail');
            em.addEventListener('click', async () => {
              const to = prompt('Send this link to which email address?');
              if (!to) return;
              try { shareMsg((await post(ds.shareEmailUrl, { id: s.id, to })).message); } catch (err) { shareMsg(err.message, true); }
            });
            act.appendChild(em);
          }
        }
        const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'icon-btn icon-btn--danger'; rm.title = 'Stop sharing'; rm.innerHTML = svg('x');
        rm.addEventListener('click', async () => {
          try { await post(ds.shareDeleteUrl, { id: s.id }); shareMsg('Sharing stopped.'); loadShare(); } catch (err) { shareMsg(err.message, true); }
        });
        act.appendChild(rm);
        li.append(ic, body, act); list.appendChild(li);
      });
    }
    function openShare(path, name) {
      if (!dlgShare) return;
      sharePath = path; shareMsg('');
      $('[data-share-name]', dlgShare).textContent = name;
      $('[data-share-link-form]', dlgShare).reset();
      dlgShare.showModal();
      loadShare();
    }
    if (dlgShare) {
      dlgShare.addEventListener('close', () => location.reload());
      $('[data-share-user-form]', dlgShare).addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        try { shareMsg((await post(ds.shareUserUrl, { path: sharePath, recipient: f.recipient.value, perms: f.perms.value })).message); loadShare(); }
        catch (err) { shareMsg(err.message, true); }
      });
      $('[data-share-link-form]', dlgShare).addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = e.target;
        try {
          const r = await post(ds.shareLinkUrl, { path: sharePath, perms: f.perms.value, expires: f.expires.value, password: f.password.value, label: f.label.value });
          f.password.value = ''; f.label.value = '';
          try { await navigator.clipboard.writeText(r.url); shareMsg('Link created and copied.'); } catch { shareMsg('Link created.'); }
          loadShare();
        } catch (err) { shareMsg(err.message, true); }
      });
    }

    // ---- Viewer ----
    const viewer = document.getElementById('viewer');
    const stage = $('[data-viewer-stage]', viewer);
    const viewItems = () => $$('.file-table tr[data-viewer]', root);
    let current = -1;
    function openViewer(path) {
      const list = viewItems();
      const idx = list.findIndex((r) => r.dataset.path === path);
      if (idx < 0) return;
      if (!viewer.open) viewer.showModal();
      show(idx);
    }
    function show(idx) {
      const list = viewItems();
      if (!list.length) return;
      current = (idx + list.length) % list.length;
      const row = list[current];
      const { path, name, viewer: kind } = row.dataset;
      const size = parseInt(row.dataset.size, 10) || 0;
      const src = q(ds.viewUrl, { path });
      $('[data-viewer-name]', viewer).textContent = name;
      $('[data-viewer-count]', viewer).textContent = list.length > 1 ? (current + 1) + ' / ' + list.length : '';
      $('[data-viewer-download]', viewer).href = q(ds.downloadUrl, { path });
      $('[data-viewer-prev]', viewer).hidden = $('[data-viewer-next]', viewer).hidden = list.length < 2;
      stage.textContent = '';
      let el;
      if (kind === 'image') {
        el = document.createElement('img'); el.alt = name; el.src = src;
      } else if (kind === 'video') {
        el = document.createElement('video'); el.controls = true; el.preload = 'metadata'; el.src = src; el.playsInline = true;
      } else if (kind === 'audio') {
        el = document.createElement('audio'); el.controls = true; el.src = src;
      } else if (kind === 'pdf') {
        el = document.createElement('iframe'); el.src = src; el.title = name;
      } else if (kind === 'text') {
        if (size > 2 * 1024 * 1024) {
          el = message('This file is too large to preview. Download it to open it.');
        } else {
          el = document.createElement('pre'); el.textContent = 'Loading…';
          fetch(src, { headers: { 'X-Requested-With': 'fetch' } })
            .then((r) => { if (!r.ok) throw new Error(); return r.text(); })
            .then((t) => { el.textContent = t; })
            .catch(() => { el.textContent = 'Could not load this file.'; });
        }
      } else {
        el = message('No preview for this type of file.');
      }
      stage.appendChild(el);
      const vb = $('[data-viewer-versions]', viewer);
      if (vb) vb.onclick = () => openVersions(path, name);
    }
    function message(text) {
      const d = document.createElement('div'); d.className = 'viewer__msg';
      const p = document.createElement('p'); p.textContent = text; d.appendChild(p);
      return d;
    }
    function closeViewer() { stage.textContent = ''; viewer.close(); }
    $('[data-viewer-close]', viewer).addEventListener('click', closeViewer);
    viewer.addEventListener('close', () => { stage.textContent = ''; }); // stops video/audio
    $('[data-viewer-prev]', viewer).addEventListener('click', () => show(current - 1));
    $('[data-viewer-next]', viewer).addEventListener('click', () => show(current + 1));
    viewer.addEventListener('keydown', (e) => {
      if (e.target.closest('video, audio')) return;
      if (e.key === 'ArrowLeft') show(current - 1);
      if (e.key === 'ArrowRight') show(current + 1);
    });

    // ---- Uploads ----
    const panel = $('[data-uploads]');
    const list = $('[data-uploads-list]');
    const title = $('[data-uploads-title]');
    $('[data-uploads-close]').addEventListener('click', () => { panel.hidden = true; });
    const queue = [];
    let running = false;
    let completed = 0;

    function fmt(n) {
      const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0;
      while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
      return (i ? n.toFixed(n < 10 ? 1 : 0) : n) + ' ' + u[i];
    }
    function addItem(file) {
      const li = document.createElement('li');
      li.className = 'upload-item';
      li.innerHTML = '<div class="upload-item__row"><span class="upload-item__name"></span><span class="upload-item__status">Waiting…</span></div><div class="bar"><span></span></div>';
      $('.upload-item__name', li).textContent = file.name;
      list.appendChild(li);
      return li;
    }
    function enqueue(fileList) {
      const arr = Array.from(fileList);
      if (!arr.length || !$('[data-upload-input]', root)) return;
      panel.hidden = false;
      arr.forEach((f) => queue.push({ file: f, el: addItem(f) }));
      title.textContent = 'Uploading ' + queue.length + ' file' + (queue.length === 1 ? '' : 's');
      if (!running) run();
    }
    function randomId() {
      const a = new Uint8Array(18); crypto.getRandomValues(a);
      return Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
    }
    function sendChunk(fd, onProgress) {
      return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', ds.uploadUrl);
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded); };
        xhr.onload = () => {
          let data = null;
          try { data = JSON.parse(xhr.responseText); } catch { /* not JSON */ }
          if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) resolve(data);
          else reject(new Error((data && data.error) || (xhr.status === 413 ? 'The server says this piece is too large.' : 'Upload failed (error ' + xhr.status + ').')));
        };
        xhr.onerror = () => reject(new Error('Network problem. Check your connection.'));
        xhr.send(fd);
      });
    }
    async function uploadOne(item) {
      const { file, el } = item;
      const status = $('.upload-item__status', el);
      const barEl = $('.bar span', el);
      const id = randomId();
      let offset = 0;
      let result = null;
      do {
        const end = Math.min(offset + chunkSize, file.size);
        const fd = new FormData();
        fd.append('upload_id', id);
        fd.append('offset', String(offset));
        fd.append('total', String(file.size));
        fd.append('final', end >= file.size ? '1' : '0');
        fd.append('dir', dir);
        fd.append('name', file.name);
        fd.append('chunk', file.slice(offset, end), 'chunk');
        let attempt = 0;
        for (;;) {
          try {
            result = await sendChunk(fd, (loaded) => {
              const pct = file.size ? ((offset + loaded) / file.size) * 100 : 100;
              barEl.style.width = Math.min(100, pct).toFixed(1) + '%';
              status.textContent = Math.floor(Math.min(100, pct)) + '% · ' + fmt(file.size);
            });
            break;
          } catch (err) {
            attempt++;
            if (attempt >= 3 || /reserved|name|space|limit|Invalid|order/i.test(err.message)) throw err;
            status.textContent = 'Retrying…';
            await new Promise((r) => setTimeout(r, 1000 * attempt));
          }
        }
        offset = end;
      } while (offset < file.size);
      barEl.style.width = '100%';
      status.textContent = result && result.replaced ? 'Replaced · old version kept' : 'Done';
      el.classList.add('is-done');
    }
    async function run() {
      running = true;
      let failed = 0;
      while (queue.length) {
        const item = queue.shift();
        try { await uploadOne(item); completed++; }
        catch (err) {
          failed++;
          item.el.classList.add('is-error');
          $('.upload-item__status', item.el).textContent = err.message;
        }
      }
      running = false;
      title.textContent = failed ? (completed + ' uploaded, ' + failed + ' failed') : 'All uploads finished';
      if (!failed) setTimeout(() => location.reload(), 900);
      else if (completed) $('[data-uploads-close]').addEventListener('click', () => location.reload(), { once: true });
    }

    const input = $('[data-upload-input]', root);
    if (input) input.addEventListener('change', () => { enqueue(input.files); input.value = ''; });

    const zone = $('[data-dropzone]', root);
    let depth = 0;
    const hasFiles = (e) => input && e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
    document.addEventListener('dragenter', (e) => { if (hasFiles(e)) { depth++; zone.classList.add('is-dragging'); } });
    document.addEventListener('dragleave', (e) => { if (hasFiles(e) && --depth <= 0) { depth = 0; zone.classList.remove('is-dragging'); } });
    document.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
    document.addEventListener('drop', (e) => {
      if (!hasFiles(e)) return;
      e.preventDefault(); depth = 0; zone.classList.remove('is-dragging');
      enqueue(e.dataTransfer.files);
    });
    window.addEventListener('beforeunload', (e) => { if (running) { e.preventDefault(); e.returnValue = ''; } });
  }
})();
