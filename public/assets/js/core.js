(function (window, document) {
  'use strict';

  window.PSRApp = window.PSRApp || { modules: [], register() {} };

  window.PSRApp.register({
    name: 'core',
    init() {
      document.addEventListener('click', function (event) {
        const el = event.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
          event.preventDefault();
        }
      });

      const clock = document.getElementById('clock');
      if (clock) {
        const tick = function () { clock.textContent = new Date().toLocaleString(); };
        tick();
        window.setInterval(tick, 1000);
      }
    }
  });
})(window, document);
