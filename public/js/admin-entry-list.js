(function () {
  'use strict';

  var selectAll = document.getElementById('admin-entry-select-all');
  var bulkBar = document.getElementById('admin-entry-bulk-bar');
  var bulkCount = document.getElementById('admin-entry-bulk-count');
  var bulkPublish = document.getElementById('admin-entry-bulk-publish');
  var bulkTrash = document.getElementById('admin-entry-bulk-trash');
  var bulkTaxonomyBtn = document.getElementById('admin-entry-bulk-taxonomy-apply');
  var bulkTermPanels = document.getElementById('admin-entry-bulk-term-panels');
  var bulkTaxonomySelect = document.getElementById('admin-entry-bulk-taxonomy-select');
  var rowCbs = document.querySelectorAll('.admin-entry-row-cb');

  if (!rowCbs.length && !selectAll) {
    return;
  }

  function selectedCount() {
    var n = 0;
    rowCbs.forEach(function (cb) {
      if (cb.checked) {
        n += 1;
      }
    });
    return n;
  }

  function syncBulkUi() {
    var n = selectedCount();
    if (bulkPublish) {
      bulkPublish.disabled = n < 1;
    }
    if (bulkTrash) {
      bulkTrash.disabled = n < 1;
    }
    if (bulkTaxonomyBtn) {
      bulkTaxonomyBtn.disabled = n < 1;
    }
    if (bulkBar) {
      bulkBar.hidden = n < 1;
      bulkBar.setAttribute('aria-hidden', n < 1 ? 'true' : 'false');
      bulkBar.classList.toggle('is-active', n > 0);
      bulkBar.classList.toggle('is-sticky', n > 0);
    }
    if (bulkCount) {
      bulkCount.hidden = n < 1;
      bulkCount.textContent = n === 1 ? '1 selected' : n + ' selected';
    }
    if (selectAll) {
      selectAll.indeterminate = n > 0 && n < rowCbs.length;
      selectAll.checked = rowCbs.length > 0 && n === rowCbs.length;
    }
    if (bulkTermPanels) {
      bulkTermPanels.hidden = n < 1;
      bulkTermPanels.setAttribute('aria-hidden', n < 1 ? 'true' : 'false');
    }
    rowCbs.forEach(function (cb) {
      var card = cb.closest('.admin-entry-card');
      if (card) {
        card.classList.toggle('is-selected', cb.checked);
      }
    });
  }

  function syncTaxonomyTermPanels() {
    if (!bulkTaxonomySelect) {
      return;
    }
    var taxId = bulkTaxonomySelect.value || '';
    document.querySelectorAll('[data-entry-bulk-taxonomy-id]').forEach(function (panel) {
      var match = panel.getAttribute('data-entry-bulk-taxonomy-id') === taxId;
      panel.hidden = !match;
      panel.setAttribute('aria-hidden', match ? 'false' : 'true');
    });
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowCbs.forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
      syncBulkUi();
    });
  }

  rowCbs.forEach(function (cb) {
    cb.addEventListener('change', syncBulkUi);
    cb.addEventListener('click', function (e) {
      e.stopPropagation();
    });
  });

  function confirmBulk(msg) {
    return window.confirm(msg);
  }

  function appendSelectedIds(form) {
    if (!form) {
      return;
    }
    form.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
      el.remove();
    });
    rowCbs.forEach(function (cb) {
      if (!cb.checked) {
        return;
      }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ids[]';
      input.value = cb.value;
      form.appendChild(input);
    });
  }

  if (bulkPublish) {
    bulkPublish.addEventListener('click', function () {
      var n = selectedCount();
      if (n < 1) {
        return;
      }
      var form = document.getElementById('admin-entry-bulk-form');
      if (!form) {
        return;
      }
      var msg = n === 1 ? 'Publish 1 selected entry?' : 'Publish ' + n + ' selected entries?';
      if (!confirmBulk(msg)) {
        return;
      }
      form.querySelector('input[name="bulk_action"]').value = 'publish';
      appendSelectedIds(form);
      form.submit();
    });
  }

  if (bulkTrash) {
    bulkTrash.addEventListener('click', function () {
      var n = selectedCount();
      if (n < 1) {
        return;
      }
      var form = document.getElementById('admin-entry-bulk-form');
      if (!form) {
        return;
      }
      var msg = n === 1
        ? 'Move 1 selected entry to trash?'
        : 'Move ' + n + ' selected entries to trash?';
      if (!confirmBulk(msg)) {
        return;
      }
      form.querySelector('input[name="bulk_action"]').value = 'trash';
      appendSelectedIds(form);
      form.submit();
    });
  }

  if (bulkTaxonomyBtn) {
    bulkTaxonomyBtn.addEventListener('click', function () {
      var n = selectedCount();
      if (n < 1) {
        return;
      }
      var form = document.getElementById('admin-entry-bulk-form');
      var taxForm = document.getElementById('admin-entry-bulk-taxonomy-form');
      if (!form || !taxForm || !bulkTaxonomySelect) {
        return;
      }
      var taxId = bulkTaxonomySelect.value;
      if (!taxId) {
        window.alert('Choose a taxonomy first.');
        return;
      }
      var panel = document.querySelector('[data-entry-bulk-taxonomy-id="' + taxId + '"]');
      if (!panel) {
        return;
      }
      var checkedTerms = panel.querySelectorAll('input[type="checkbox"]:checked');
      if (checkedTerms.length < 1) {
        window.alert('Select at least one term.');
        return;
      }
      var msg = n === 1
        ? 'Apply taxonomy terms to 1 selected entry?'
        : 'Apply taxonomy terms to ' + n + ' selected entries?';
      if (!confirmBulk(msg)) {
        return;
      }

      taxForm.querySelectorAll('input[name="term_ids[]"]').forEach(function (el) {
        el.remove();
      });
      checkedTerms.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'term_ids[]';
        input.value = cb.value;
        taxForm.appendChild(input);
      });

      var modeEl = document.querySelector('input[name="entry_bulk_taxonomy_mode"]:checked');
      if (modeEl) {
        var modeHidden = taxForm.querySelector('input[name="taxonomy_mode"]');
        if (modeHidden) {
          modeHidden.value = modeEl.value;
        }
      }

      taxForm.querySelector('input[name="taxonomy_id"]').value = taxId;

      taxForm.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
        el.remove();
      });
      appendSelectedIds(taxForm);

      taxForm.submit();
    });
  }

  if (bulkTaxonomySelect) {
    bulkTaxonomySelect.addEventListener('change', syncTaxonomyTermPanels);
    syncTaxonomyTermPanels();
  }

  syncBulkUi();
})();
