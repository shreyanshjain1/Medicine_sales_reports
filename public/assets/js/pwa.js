(function (window) {
  'use strict';

  window.PSRApp = window.PSRApp || { modules: [], register() {} };

  window.PSRApp.register({
    name: 'pwa',
    init() {
      if (!('serviceWorker' in navigator)) return;
      window.addEventListener('load', function () {
        navigator.serviceWorker.register((window.BASE_URL || '') + '/sw.js').catch(function (err) {
          console.warn('Service worker registration failed', err);
        });
      });
    }
  });
})(window);
