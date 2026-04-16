(function (window, document) {
  'use strict';

  window.PSRApp = window.PSRApp || {
    modules: [],
    register(module) {
      if (module && typeof module.init === 'function') this.modules.push(module);
    },
    init() {
      this.modules.forEach((module) => {
        try { module.init(); } catch (err) { console.error('[PSRApp]', module.name || 'module', err); }
      });
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    window.PSRApp.init();
  });
})(window, document);
