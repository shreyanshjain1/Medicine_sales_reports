// Confirm prompts
document.addEventListener('click', (e)=>{
  if (e.target.matches('[data-confirm]')) {
    if (!confirm(e.target.getAttribute('data-confirm'))) e.preventDefault();
  }
});

// City + Doctor (Quick Task) ✅ Tablet-friendly: type + dropdown (Select2)
document.addEventListener('DOMContentLoaded', async ()=>{
  const citySel = document.getElementById('qe_city');
  const docSel  = document.getElementById('qe_doctor');
  const titleEl = document.getElementById('qe_title');

  if (!citySel || !docSel) return;

  // If server didn’t preload cities, fetch them
  if (citySel.options.length <= 1) {
    try {
      const r = await fetch(window.BASE_URL + '/api/api_doctors.php?mode=cities', {cache:'no-store'});
      const j = await r.json();
      (((j && j.data && Array.isArray(j.data.cities)) ? j.data.cities : [])).forEach(c=>{
        const o = document.createElement('option');
        o.value = c;
        o.textContent = c;
        citySel.appendChild(o);
      });
    } catch(_) {}
  }

  async function waitForSelect2(){
    for (let i = 0; i < 80; i++) {
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) return true;
      await new Promise(r => setTimeout(r, 100));
    }
    return false;
  }

  const hasSelect2 = await waitForSelect2();
  if (!hasSelect2) return;

  const $ = window.jQuery;

  // ✅ Force Select2 search field to be usable on Android/iOS
  function enableTouchTyping(selectEl){
    $(selectEl).on('select2:open', ()=>{
      const searchField = document.querySelector('.select2-container--open .select2-search__field');
      if (searchField) {
        searchField.setAttribute('inputmode', 'search');
        searchField.setAttribute('autocomplete', 'off');
        searchField.setAttribute('autocorrect', 'off');
        searchField.setAttribute('autocapitalize', 'none');
        searchField.focus({ preventScroll: true });
        setTimeout(()=>searchField.focus({ preventScroll: true }), 50);
      }
    });
  }

  // ✅ Make BOTH City and Doctor searchable dropdowns
  function initCitySelect2(){
    if ($(citySel).hasClass('select2-hidden-accessible')) return;
    $(citySel).select2({
      width: '100%',
      placeholder: 'Select City',
      allowClear: true,
      minimumResultsForSearch: 0 // ALWAYS show search box
    });
    enableTouchTyping(citySel);
  }

  function initDoctorSelect2(){
    if ($(docSel).hasClass('select2-hidden-accessible')) return;
    $(docSel).select2({
      width: '100%',
      placeholder: 'Select Doctor',
      allowClear: true,
      minimumResultsForSearch: 0 // ALWAYS show search box
    });
    enableTouchTyping(docSel);

    // Auto-title when doctor changes
    $(docSel).on('change', ()=>{
      if (!titleEl || titleEl.value) return;
      const opt = docSel.selectedOptions && docSel.selectedOptions[0];
      if (opt) titleEl.value = 'Visit: ' + (opt.dataset.drName || opt.textContent);
    });
  }

  initCitySelect2();
  initDoctorSelect2();

  function refreshDoctorSelect2(){
    $(docSel).trigger('change.select2');
  }

  async function loadDoctors(city){
    const c = (city || '').trim();

    // reset doctor list
    docSel.innerHTML = '<option value="">Select Doctor</option>';
    docSel.disabled = true;
    $(docSel).val('').trigger('change.select2');

    if (!c) return;

    try{
      const r = await fetch(window.BASE_URL + '/api/api_doctors.php?city=' + encodeURIComponent(c), {cache:'no-store'});
      const j = await r.json();
      const list = (j && j.data && Array.isArray(j.data.doctors)) ? j.data.doctors : [];

      if (!list.length) {
        const o = document.createElement('option');
        o.value = '';
        o.textContent = 'No doctors found';
        docSel.appendChild(o);
        docSel.disabled = true;
        refreshDoctorSelect2();
        return;
      }

      list.forEach(d=>{
        const o = document.createElement('option');
        o.value = String(d.id || '');
        o.textContent = (d.dr_name || 'Doctor') + (d.speciality ? ' — ' + d.speciality : '');
        o.dataset.drName = (d.dr_name || '');
        docSel.appendChild(o);
      });

      docSel.disabled = false;
      refreshDoctorSelect2();
    } catch(err){
      console.error(err);
      docSel.innerHTML = '<option value="">Error loading doctors</option>';
      docSel.disabled = true;
      refreshDoctorSelect2();
    }
  }

  // When city changes, load doctors
  $(citySel).on('change', ()=>loadDoctors(citySel.value));

  // If city pre-selected, load doctors
  if (citySel.value) loadDoctors(citySel.value);
});


/* ---------------------------
   Offline Reports (PWA outbox)
   --------------------------- */
(function(){
  const DB_NAME = 'pharmastar_reporting';
  const DB_VER  = 1;
  const STORE   = 'report_outbox';

  function log(){ /* quiet */ }

  function openDB(){
    return new Promise((resolve, reject)=>{
      const req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          const st = db.createObjectStore(STORE, { keyPath: 'client_id' });
          st.createIndex('created_at', 'created_at');
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error);
    });
  }

  async function withStore(mode, fn){
    const db = await openDB();
    return new Promise((resolve, reject)=>{
      const tx = db.transaction(STORE, mode);
      const st = tx.objectStore(STORE);
      const out = fn(st);
      tx.oncomplete = ()=>resolve(out);
      tx.onerror = ()=>reject(tx.error);
      tx.onabort = ()=>reject(tx.error);
    });
  }

  async function outboxPut(item){
    return withStore('readwrite', (st)=>st.put(item));
  }

  async function outboxGetAll(){
    const db = await openDB();
    return new Promise((resolve, reject)=>{
      const tx = db.transaction(STORE, 'readonly');
      const st = tx.objectStore(STORE);
      const req = st.getAll();
      req.onsuccess = ()=>resolve(req.result || []);
      req.onerror = ()=>reject(req.error);
    });
  }

  async function outboxDelete(client_id){
    return withStore('readwrite', (st)=>st.delete(client_id));
  }

  async function outboxCount(){
    const db = await openDB();
    return new Promise((resolve, reject)=>{
      const tx = db.transaction(STORE, 'readonly');
      const st = tx.objectStore(STORE);
      const req = st.count();
      req.onsuccess = ()=>resolve(req.result || 0);
      req.onerror = ()=>reject(req.error);
    });
  }

  function qs(id){ return document.getElementById(id); }

  async function isActuallyOnline(){
    try {
      const r = await fetch(window.BASE_URL + '/api/ping.php?ts=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
      return !!(r && (r.ok || r.status === 204));
    } catch (e) {
      return false;
    }
  }

  async function updateBadge(){
    const badge = qs('offlineQueueBadge');
    const status = qs('offlineNetStatus');
    if (!badge) return;
    let n = 0;
    try { n = await outboxCount(); } catch(e){}
    badge.textContent = String(n);
    if (status) {
      const ok = await isActuallyOnline();
      status.textContent = ok ? 'Online' : 'Offline';
    }
  }

  function makeClientId(){
    return 'r_' + Date.now() + '_' + Math.random().toString(16).slice(2) + '_' + Math.random().toString(16).slice(2);
  }

  function fileToBase64(file){
    return new Promise((resolve, reject)=>{
      const fr = new FileReader();
      fr.onload = ()=>resolve(fr.result);
      fr.onerror = ()=>reject(fr.error);
      fr.readAsDataURL(file);
    });
  }

  async function serializeReportForm(form){
    const fd = new FormData(form);
    const obj = {
      client_id: makeClientId(),
      doctor_name: (fd.get('doctor_name') || '').toString(),
      doctor_email: (fd.get('doctor_email') || '').toString(),
      purpose: (fd.get('purpose') || '').toString(),
      medicine_name: (fd.get('medicine_name') || '').toString(),
      hospital_name: (fd.get('hospital_name') || '').toString(),
      visit_datetime: (fd.get('visit_datetime') || '').toString(),
      summary: (fd.get('summary') || '').toString(),
      remarks: (fd.get('remarks') || '').toString(),
      signature_data: (fd.get('signature_data') || '').toString(),
      attachment: null
    };

    const file = fd.get('attachment');
    if (file && typeof file === 'object' && file.name) {
      const dataUrl = await fileToBase64(file);
      obj.attachment = { name: file.name, mime: file.type || '', data: dataUrl };
    }
    return obj;
  }

  async function syncOutbox(){
    const online = await isActuallyOnline();
    if (!online) return { ok:false, offline:true };

    const items = await outboxGetAll();
    if (!items.length) { await updateBadge(); return { ok:true, synced:0 }; }

    const body = JSON.stringify({
      _token: window.CSRF_TOKEN || '',
      items: items.map(x => x.payload)
    });

    const res = await fetch(window.BASE_URL + '/api/api_sync_reports.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body
    });

    const data = await res.json().catch(()=>null);
    if (!res.ok || !data || !data.success) throw new Error((data && data.message) ? data.message : 'Sync failed');

    const results = (data && data.data && Array.isArray(data.data.results)) ? data.data.results : [];
    let okCount = 0;

    for (const r of results) {
      if (r && r.success && r.client_id) {
        await outboxDelete(r.client_id);
        okCount++;
      }
    }

    await updateBadge();
    return { ok:true, synced: okCount };
  }

  // Manual sync button
  const btn = qs('syncNowBtn');
  if (btn) {
    btn.addEventListener('click', async ()=>{
      btn.disabled = true;
      try {
        const res = await syncOutbox();
        if (res.ok) alert(res.synced ? ('Synced ' + res.synced + ' report(s).') : 'Nothing to sync.');
        else if (res.offline) alert('You are offline. Connect to the internet then tap Sync again.');
      } catch(e) {
        alert('Sync failed: ' + (e && e.message ? e.message : 'Please ensure you are logged in and online.'));
      } finally {
        btn.disabled = false;
        updateBadge();
      }
    });
  }

  // Initial badge paint
  updateBadge();

  window.addEventListener('online', ()=>{ syncOutbox().catch(()=>{}); updateBadge(); });
  window.addEventListener('offline', ()=>{ updateBadge(); });

  /* -------- Signature pad fallback (no CDN required) -------- */
  window.SimpleSignaturePad = function(canvas){
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let empty = true;
    let lastX = 0, lastY = 0;

    function getPos(e){
      const r = canvas.getBoundingClientRect();
      const x = (e.clientX - r.left) * (canvas.width / r.width);
      const y = (e.clientY - r.top) * (canvas.height / r.height);
      return {x,y};
    }

    function down(e){
      drawing = true;
      const p = getPos(e);
      lastX = p.x; lastY = p.y;
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      ctx.moveTo(lastX, lastY);
      empty = false;
      e.preventDefault();
    }

    function move(e){
      if (!drawing) return;
      const p = getPos(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      lastX = p.x; lastY = p.y;
      e.preventDefault();
    }

    function up(e){
      drawing = false;
      e.preventDefault();
    }

    canvas.addEventListener('pointerdown', down);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);

    return {
      clear(){
        ctx.clearRect(0,0,canvas.width, canvas.height);
        empty = true;
      },
      isEmpty(){ return empty; },
      toDataURL(type='image/png'){ return canvas.toDataURL(type); }
    };
  };

  /* -------- Intercept Add Report submit when offline -------- */
  document.addEventListener('DOMContentLoaded', ()=>{
    const form = document.getElementById('reportForm');
    if (!form) return;

    form.addEventListener('submit', async (e)=>{
      e.preventDefault();

      // ensure signature hidden is updated (both online/offline)
      if (typeof window.__captureReportSignature === 'function') {
        try { window.__captureReportSignature(); } catch(err){}
      }

      // Validate required fields locally before doing anything
      const fdCheck = new FormData(form);
      const doctorNameCheck = (fdCheck.get('doctor_name') || '').toString().trim();
      const visitCheck = (fdCheck.get('visit_datetime') || '').toString().trim();
      if (!doctorNameCheck || !visitCheck) {
        alert('Doctor Name and Visit Date/Time are required.');
        return;
      }

      // Attempt online submit (multipart) using fetch
      const fd = new FormData(form);
      try {
        const ctrl = new AbortController();
        const t = setTimeout(()=>ctrl.abort(), 12000);
        const res = await fetch(form.getAttribute('action') || window.location.href, {
          method: (form.getAttribute('method') || 'POST').toUpperCase(),
          body: fd,
          credentials: 'same-origin',
          cache: 'no-store',
          redirect: 'follow',
          signal: ctrl.signal
        });
        clearTimeout(t);

        // If server accepted, follow the final URL (usually reports.php or view page)
        if (res && (res.ok || (res.status >= 300 && res.status < 400))) {
          if (res.url) window.location.href = res.url;
          else window.location.reload();
          return;
        }

        throw new Error('Server responded with ' + (res ? res.status : 'no response'));
      } catch (err) {
        // Offline / failed submission → save outbox
        try {
          const payload = await serializeReportForm(form);

          if (!payload.doctor_name || !payload.visit_datetime) {
            alert('Doctor Name and Visit Date/Time are required.');
            return;
          }

          await outboxPut({
            client_id: payload.client_id,
            created_at: Date.now(),
            payload
          });

          await updateBadge();
          alert('Saved offline. It will upload automatically when the tablet is online again.');

          // reset form & signature
          form.reset();
          const hidden = document.getElementById('signature_data');
          if (hidden) hidden.value = '';
          const clearBtn = document.getElementById('clearSig');
          if (clearBtn) clearBtn.click();
        } catch (e2) {
          alert('Failed to save offline. Please try again.');
        }
      }
    });
  });

})();
