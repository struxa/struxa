/**
 * Row action menus: use fixed positioning so .admin-table-wrap overflow-x does not
 * create a spurious scroll area when a dropdown opens.
 */
(function () {
  'use strict';

  function clearPanel(menu) {
    var panel = menu.querySelector('.admin-actions-menu-panel');
    if (panel) {
      panel.style.cssText = '';
    }
  }

  function closeAll() {
    document.querySelectorAll('.admin-actions-menu[open]').forEach(function (menu) {
      menu.removeAttribute('open');
      clearPanel(menu);
    });
  }

  function positionPanel(menu) {
    var panel = menu.querySelector('.admin-actions-menu-panel');
    var trig = menu.querySelector('.admin-actions-menu-trigger');
    if (!panel || !trig) {
      return;
    }

    if (!menu.open) {
      clearPanel(menu);
      return;
    }

    function apply() {
      var r = trig.getBoundingClientRect();
      var gap = 6;
      panel.style.cssText = '';
      panel.style.position = 'fixed';
      panel.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
      panel.style.left = 'auto';
      panel.style.top = r.bottom + gap + 'px';
      panel.style.zIndex = '300';
      panel.style.minWidth = Math.max(152, r.width) + 'px';

      var pr = panel.getBoundingClientRect();
      if (pr.bottom > window.innerHeight - 10) {
        var above = r.top - pr.height - gap;
        if (above >= 8) {
          panel.style.top = above + 'px';
        }
      }
      if (pr.right > window.innerWidth - 8) {
        panel.style.right = '8px';
      }
    }

    requestAnimationFrame(function () {
      requestAnimationFrame(apply);
    });
  }

  function bindScrollTargets() {
    document.querySelectorAll('.admin-table-wrap').forEach(function (el) {
      el.addEventListener('scroll', closeAll, { passive: true });
    });
    var main = document.querySelector('.admin-main-inner');
    if (main) {
      main.addEventListener('scroll', closeAll, { passive: true });
    }
  }

  function init() {
    document.querySelectorAll('.admin-actions-menu').forEach(function (menu) {
      menu.addEventListener('toggle', function () {
        positionPanel(menu);
      });
    });

    window.addEventListener('resize', closeAll);
    window.addEventListener('scroll', closeAll, true);
    bindScrollTargets();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
