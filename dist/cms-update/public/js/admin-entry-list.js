(function () {
  'use strict';

  function rowCheckboxes() {
    return document.querySelectorAll('.admin-entry-row-cb');
  }

  var selectAll = document.getElementById('admin-entry-select-all');
  var bulkBar = document.getElementById('admin-entry-bulk-bar');
  var bulkCount = document.getElementById('admin-entry-bulk-count');
  var bulkPublish = document.getElementById('admin-entry-bulk-publish');
  var bulkTrash = document.getElementById('admin-entry-bulk-trash');
  var bulkTaxonomyBtn = document.getElementById('admin-entry-bulk-taxonomy-apply');
  var bulkTermPanels = document.getElementById('admin-entry-bulk-term-panels');
  var bulkTaxonomySelect = document.getElementById('admin-entry-bulk-taxonomy-select');
  var bulkForm = document.getElementById('admin-entry-bulk-form');
  var bulkTaxForm = document.getElementById('admin-entry-bulk-taxonomy-form');

  if (!selectAll && rowCheckboxes().length === 0) {
    return;
  }

  function selectedCount() {
    var n = 0;
    rowCheckboxes().forEach(function (cb) {
      if (cb.checked) {
        n += 1;
      }
    });
    return n;
  }

  function syncBulkUi() {
    var rows = rowCheckboxes();
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
      bulkBar.classList.toggle('is-active', n > 0);
      bulkBar.classList.toggle('is-sticky', n > 0);
    }
    if (bulkCount) {
      bulkCount.hidden = n < 1;
      bulkCount.textContent = n === 1 ? '1 selected' : n + ' selected';
    }
    if (selectAll) {
      selectAll.indeterminate = n > 0 && n < rows.length;
      selectAll.checked = rows.length > 0 && n === rows.length;
    }
    if (bulkTermPanels) {
      bulkTermPanels.hidden = n < 1;
      bulkTermPanels.setAttribute('aria-hidden', n < 1 ? 'true' : 'false');
    }
    rows.forEach(function (cb) {
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

  function setAllRowsChecked(checked) {
    rowCheckboxes().forEach(function (cb) {
      cb.checked = checked;
    });
    syncBulkUi();
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      setAllRowsChecked(selectAll.checked);
    });
    selectAll.addEventListener('click', function (e) {
      e.stopPropagation();
    });
  }

  document.addEventListener('change', function (e) {
    var target = e.target;
    if (target && target.classList && target.classList.contains('admin-entry-row-cb')) {
      syncBulkUi();
    }
  });

  document.addEventListener('click', function (e) {
    var target = e.target;
    if (target && target.classList && target.classList.contains('admin-entry-row-cb')) {
      e.stopPropagation();
    }
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
    rowCheckboxes().forEach(function (cb) {
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

  if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
      var n = selectedCount();
      if (n < 1) {
        e.preventDefault();
        return;
      }

      var submitter = e.submitter;
      var action = submitter && submitter.name === 'bulk_action' ? String(submitter.value || '') : '';
      var msg = '';

      if (action === 'publish') {
        msg = n === 1 ? 'Publish 1 selected entry?' : 'Publish ' + n + ' selected entries?';
      } else if (action === 'trash') {
        msg = n === 1
          ? 'Move 1 selected entry to trash?'
          : 'Move ' + n + ' selected entries to trash?';
      } else {
        e.preventDefault();
        return;
      }

      if (!confirmBulk(msg)) {
        e.preventDefault();
        return;
      }

      appendSelectedIds(bulkForm);
    });
  }

  if (bulkTaxForm) {
    bulkTaxForm.addEventListener('submit', function (e) {
      var n = selectedCount();
      if (n < 1) {
        e.preventDefault();
        return;
      }
      if (!bulkTaxonomySelect) {
        e.preventDefault();
        return;
      }

      var taxId = bulkTaxonomySelect.value;
      if (!taxId) {
        e.preventDefault();
        window.alert('Choose a taxonomy first.');
        return;
      }

      var panel = document.querySelector('[data-entry-bulk-taxonomy-id="' + taxId + '"]');
      if (!panel) {
        e.preventDefault();
        return;
      }

      var checkedTerms = panel.querySelectorAll('input[type="checkbox"]:checked');
      if (checkedTerms.length < 1) {
        e.preventDefault();
        window.alert('Select at least one term.');
        return;
      }

      var msg = n === 1
        ? 'Apply taxonomy terms to 1 selected entry?'
        : 'Apply taxonomy terms to ' + n + ' selected entries?';
      if (!confirmBulk(msg)) {
        e.preventDefault();
        return;
      }

      bulkTaxForm.querySelectorAll('input[name="term_ids[]"]').forEach(function (el) {
        el.remove();
      });
      checkedTerms.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'term_ids[]';
        input.value = cb.value;
        bulkTaxForm.appendChild(input);
      });

      var modeEl = document.querySelector('input[name="entry_bulk_taxonomy_mode"]:checked');
      if (modeEl) {
        var modeHidden = bulkTaxForm.querySelector('input[name="taxonomy_mode"]');
        if (modeHidden) {
          modeHidden.value = modeEl.value;
        }
      }

      var taxHidden = bulkTaxForm.querySelector('input[name="taxonomy_id"]');
      if (taxHidden) {
        taxHidden.value = taxId;
      }

      appendSelectedIds(bulkTaxForm);
    });
  }

  if (bulkTaxonomySelect) {
    bulkTaxonomySelect.addEventListener('change', syncTaxonomyTermPanels);
    syncTaxonomyTermPanels();
  }

  syncBulkUi();
})();
