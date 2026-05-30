/**
 * Row action menus: use fixed positioning so .admin-table-wrap overflow-x does not
 * create a spurious scroll area when a dropdown opens.
 */
(function () {
  'use strict';

  var FOLDER_MENU_Z = 10000;
  var DEFAULT_MENU_Z = 300;

  function panelFor(menu) {
    if (menu._actionsPanelEl) {
      return menu._actionsPanelEl;
    }
    var panel = menu.querySelector('.admin-actions-menu-panel');
    if (panel) {
      menu._actionsPanelEl = panel;
    }
    return panel;
  }

  function clearPanel(menu) {
    var panel = panelFor(menu);
    if (panel) {
      panel.style.cssText = '';
      if (menu.classList.contains('admin-media-folder-menu') && panel.parentElement === document.body) {
        menu.appendChild(panel);
      }
    }
  }

  function closeAll() {
    document.querySelectorAll('.admin-actions-menu[open]').forEach(function (menu) {
      menu.removeAttribute('open');
      clearPanel(menu);
    });
  }

  function positionPanel(menu) {
    var panel = panelFor(menu);
    var trig = menu.querySelector('.admin-actions-menu-trigger');
    if (!panel || !trig) {
      return;
    }

    if (!menu.open) {
      clearPanel(menu);
      return;
    }

    if (menu.classList.contains('admin-media-folder-menu') && panel.parentElement !== document.body) {
      document.body.appendChild(panel);
    }

    function apply() {
      var r = trig.getBoundingClientRect();
      var gap = 6;
      var isFolderMenu = menu.classList.contains('admin-media-folder-menu');
      panel.style.cssText = '';
      panel.style.position = 'fixed';
      panel.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
      panel.style.left = 'auto';
      panel.style.top = r.bottom + gap + 'px';
      panel.style.zIndex = String(isFolderMenu ? FOLDER_MENU_Z : DEFAULT_MENU_Z);
      var minW = isFolderMenu ? 216 : Math.max(152, r.width);
      panel.style.minWidth = minW + 'px';

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
