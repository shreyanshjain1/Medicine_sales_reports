(function (window, document) {
  'use strict';

  const DB_NAME = 'pharmastar_reporting';
  const DB_VER = 1;
  const STORE = 'report_outbox';

  function qs(id) { return document.getElementById(id); }

  function openDB() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = function () {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          const st = db.createObjectStore(STORE, { keyPath: 'client_id' });
          st.createIndex('created_at', 'created_at');
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  async function withStore(mode, fn) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const st = tx.objectStore(STORE);
      const result = fn(st);
      tx.oncomplete = function () { resolve(result); };
      tx.onerror = function () { reject(tx.error); };
      tx.onabort = function () { reject(tx.error); };
    });
  }

  function fileToBase64(file) {
    return new Promise((resolve, reject) => {
      const fr = new FileReader();
      fr.onload = function () { resolve(fr.result); };
      fr.onerror = function () { reject(fr.error); };
      fr.readAsDataURL(file);
    });
  }

  function makeClientId() {
    return 'r_' + Date.now() + '_' + Math.random().toString(16).slice(2) + '_' + Math.random().toString(16).slice(2);
  }

  async function isActuallyOnline() {
    try {
      const r = await fetch((window.BASE_URL || '') + '/api/ping.php?ts=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
      return !!(r && (r.ok || r.status === 204));
    } catch (err) {
      return false;
    }
  }

  async function outboxPut(item) { return withStore('readwrite', (st) => st.put(item)); }
  async function outboxDelete(clientId) { return withStore('readwrite', (st) => st.delete(clientId)); }
  async function outboxGetAll() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const st = tx.objectStore(STORE);
      const req = st.getAll();
      req.onsuccess = function () { resolve(req.result || []); };
      req.onerror = function () { reject(req.error); };
    });
  }
  async function outboxCount() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const st = tx.objectStore(STORE);
      const req = st.count();
      req.onsuccess = function () { resolve(req.result || 0); };
      req.onerror = function () { reject(req.error); };
    });
  }

  async function updateBadge() {
    const badge = qs('offlineQueueBadge');
    const status = qs('offlineNetStatus');
    if (!badge) return;
    let count = 0;
    try { count = await outboxCount(); } catch (err) {}
    badge.textContent = String(count);
    if (status) status.textContent = (await isActuallyOnline()) ? 'Online' : 'Offline';
  }

  async function serializeReportForm(form) {
    const fd = new FormData(form);
    const payload = {
      client_id: makeClientId(),
      doctor_name: String(fd.get('doctor_name') || ''),
      doctor_email: String(fd.get('doctor_email') || ''),
      purpose: String(fd.get('purpose') || ''),
      medicine_name: String(fd.get('medicine_name') || ''),
      hospital_name: String(fd.get('hospital_name') || ''),
      visit_datetime: String(fd.get('visit_datetime') || ''),
      summary: String(fd.get('summary') || ''),
      remarks: String(fd.get('remarks') || ''),
      signature_data: String(fd.get('signature_data') || ''),
      attachment: null
    };

    const file = fd.get('attachment');
    if (file && typeof file === 'object' && file.name) {
      payload.attachment = { name: file.name, mime: file.type || '', data: await fileToBase64(file) };
    }
    return payload;
  }

  async function syncOutbox() {
    if (!(await isActuallyOnline())) return { ok: false, offline: true };
    const items = await outboxGetAll();
    if (!items.length) { await updateBadge(); return { ok: true, synced: 0 }; }

    const response = await fetch((window.BASE_URL || '') + '/api/api_sync_reports.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ _token: window.CSRF_TOKEN || '', items: items.map((x) => x.payload) })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data || !data.ok) throw new Error((data && data.error) || 'Sync failed');

    const results = Array.isArray(data.results) ? data.results : [];
    let synced = 0;
    for (const row of results) {
      if (row && row.ok && row.client_id) {
        await outboxDelete(row.client_id);
        synced += 1;
      }
    }
    await updateBadge();
    return { ok: true, synced };
  }

  function bootManualSync() {
    const button = qs('syncNowBtn');
    if (!button) return;
    button.addEventListener('click', async function () {
      button.disabled = true;
      try {
        const result = await syncOutbox();
        if (result.ok) {
          window.alert(result.synced ? ('Synced ' + result.synced + ' report(s).') : 'Nothing to sync.');
        } else if (result.offline) {
          window.alert('You are offline. Connect to the internet then tap Sync again.');
        }
      } catch (err) {
        window.alert('Sync failed: ' + (err && err.message ? err.message : 'Please ensure you are logged in and online.'));
      } finally {
        button.disabled = false;
        updateBadge();
      }
    });
  }

  function bootSignatureFallback() {
    window.SimpleSignaturePad = function (canvas) {
      const ctx = canvas.getContext('2d');
      let drawing = false;
      let empty = true;
      function getPos(e) {
        const r = canvas.getBoundingClientRect();
        return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
      }
      function down(e) {
        drawing = true;
        const p = getPos(e);
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        empty = false;
        e.preventDefault();
      }
      function move(e) {
        if (!drawing) return;
        const p = getPos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        e.preventDefault();
      }
      function up(e) { drawing = false; e.preventDefault(); }
      canvas.addEventListener('pointerdown', down);
      canvas.addEventListener('pointermove', move);
      window.addEventListener('pointerup', up);
      return { clear() { ctx.clearRect(0, 0, canvas.width, canvas.height); empty = true; }, isEmpty() { return empty; }, toDataURL(type) { return canvas.toDataURL(type || 'image/png'); } };
    };
  }

  function bootReportFormInterceptor() {
    const form = document.getElementById('reportForm');
    if (!form) return;
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (typeof window.__captureReportSignature === 'function') {
        try { window.__captureReportSignature(); } catch (err) {}
      }

      const fdCheck = new FormData(form);
      if (!String(fdCheck.get('doctor_name') || '').trim() || !String(fdCheck.get('visit_datetime') || '').trim()) {
        window.alert('Doctor Name and Visit Date/Time are required.');
        return;
      }

      try {
        const fd = new FormData(form);
        const ctrl = new AbortController();
        const timeout = window.setTimeout(function () { ctrl.abort(); }, 12000);
        const response = await fetch(form.getAttribute('action') || window.location.href, {
          method: (form.getAttribute('method') || 'POST').toUpperCase(),
          body: fd,
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow',
          signal: ctrl.signal
        });
        window.clearTimeout(timeout);
        if (response && (response.ok || (response.status >= 300 && response.status < 400))) {
          window.location.href = response.url || window.location.href;
          return;
        }
        throw new Error('Server responded with ' + (response ? response.status : 'no response'));
      } catch (err) {
        try {
          const payload = await serializeReportForm(form);
          await outboxPut({ client_id: payload.client_id, created_at: Date.now(), payload });
          await updateBadge();
          window.alert('Saved offline. It will upload automatically when the tablet is online again.');
          form.reset();
          const hidden = document.getElementById('signature_data');
          if (hidden) hidden.value = '';
          const clearBtn = document.getElementById('clearSig');
          if (clearBtn) clearBtn.click();
        } catch (saveErr) {
          window.alert('Failed to save offline. Please try again.');
        }
      }
    });
  }

  window.PSRApp = window.PSRApp || { modules: [], register() {} };
  window.PSRApp.register({
    name: 'offline-reports',
    init() {
      bootSignatureFallback();
      bootManualSync();
      bootReportFormInterceptor();
      updateBadge();
      window.addEventListener('online', function () { syncOutbox().catch(() => {}); updateBadge(); });
      window.addEventListener('offline', function () { updateBadge(); });
    }
  });
})(window, document);
