(function (window, document) {
  'use strict';

  function waitForSelect2() {
    return new Promise((resolve) => {
      let tries = 0;
      const timer = window.setInterval(function () {
        tries += 1;
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
          clearInterval(timer);
          resolve(true);
        } else if (tries >= 80) {
          clearInterval(timer);
          resolve(false);
        }
      }, 100);
    });
  }

  function enableTouchTyping($, selectEl) {
    $(selectEl).on('select2:open', function () {
      const searchField = document.querySelector('.select2-container--open .select2-search__field');
      if (!searchField) return;
      searchField.setAttribute('inputmode', 'search');
      searchField.setAttribute('autocomplete', 'off');
      searchField.setAttribute('autocorrect', 'off');
      searchField.setAttribute('autocapitalize', 'none');
      searchField.focus({ preventScroll: true });
      window.setTimeout(function () { searchField.focus({ preventScroll: true }); }, 50);
    });
  }

  function addOption(select, value, text, data) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = text;
    Object.keys(data || {}).forEach(function (key) {
      option.dataset[key] = data[key];
    });
    select.appendChild(option);
  }

  window.PSRApp = window.PSRApp || { modules: [], register() {} };

  window.PSRApp.register({
    name: 'quick-task',
    async init() {
      const citySel = document.getElementById('qe_city');
      const docSel = document.getElementById('qe_doctor');
      const titleEl = document.getElementById('qe_title');
      if (!citySel || !docSel) return;

      if (citySel.options.length <= 1) {
        try {
          const response = await fetch((window.BASE_URL || '') + '/api/api_doctors.php?mode=cities', { cache: 'no-store' });
          const json = await response.json();
          (json.cities || []).forEach(function (city) {
            addOption(citySel, city, city);
          });
        } catch (err) {
          console.warn('City preload failed', err);
        }
      }

      const hasSelect2 = await waitForSelect2();
      if (!hasSelect2) return;

      const $ = window.jQuery;
      if (!$(citySel).hasClass('select2-hidden-accessible')) {
        $(citySel).select2({ width: '100%', placeholder: 'Select City', allowClear: true, minimumResultsForSearch: 0 });
        enableTouchTyping($, citySel);
      }

      if (!$(docSel).hasClass('select2-hidden-accessible')) {
        $(docSel).select2({ width: '100%', placeholder: 'Select Doctor', allowClear: true, minimumResultsForSearch: 0 });
        enableTouchTyping($, docSel);
      }

      $(docSel).on('change', function () {
        if (!titleEl || titleEl.value) return;
        const opt = docSel.selectedOptions && docSel.selectedOptions[0];
        if (opt) titleEl.value = 'Visit: ' + (opt.dataset.drName || opt.textContent);
      });

      async function loadDoctors(city) {
        const selectedCity = (city || '').trim();
        docSel.innerHTML = '<option value="">Select Doctor</option>';
        docSel.disabled = true;
        $(docSel).val('').trigger('change.select2');
        if (!selectedCity) return;

        try {
          const response = await fetch((window.BASE_URL || '') + '/api/api_doctors.php?city=' + encodeURIComponent(selectedCity), { cache: 'no-store' });
          const json = await response.json();
          const doctors = json.doctors || [];

          if (!doctors.length) {
            addOption(docSel, '', 'No doctors found');
            $(docSel).trigger('change.select2');
            return;
          }

          doctors.forEach(function (doctor) {
            addOption(docSel, String(doctor.id || ''), (doctor.dr_name || 'Doctor') + (doctor.speciality ? ' — ' + doctor.speciality : ''), {
              drName: doctor.dr_name || ''
            });
          });

          docSel.disabled = false;
          $(docSel).trigger('change.select2');
        } catch (err) {
          console.error(err);
          docSel.innerHTML = '<option value="">Error loading doctors</option>';
          docSel.disabled = true;
          $(docSel).trigger('change.select2');
        }
      }

      $(citySel).on('change', function () { loadDoctors(citySel.value); });
      if (citySel.value) loadDoctors(citySel.value);
    }
  });
})(window, document);
