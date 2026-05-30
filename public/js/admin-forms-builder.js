/**
 * Form builder: palette, drag-reorder with AJAX save, expandable field editor.
 */
(function () {
  'use strict';

  var root = document.getElementById('admin-forms-builder');
  if (!root) return;

  var list = document.getElementById('forms-field-list');
  var statusEl = document.getElementById('admin-fb-status');
  var reorderUrl = root.getAttribute('data-reorder-url') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var dragEl = null;
  var reorderTimer = null;
  var dropIndicator = null;

  var typeSelect = document.getElementById('forms-new-field-type');
  var typeLabelEl = document.getElementById('admin-fb-add-type-label');
  var typeChip = document.getElementById('admin-fb-add-type-chip');
  var addPanel = document.getElementById('admin-fb-add-panel');
  var addTitle = document.getElementById('admin-fb-add-title');
  var labelInput = document.getElementById('forms-new-field-label');
  var choiceWrap = document.getElementById('forms-choice-options');
  var fileWrap = document.getElementById('forms-file-options-wrap');
  var quizWrap = document.getElementById('forms-quiz-options-wrap');
  var emptyState = document.getElementById('admin-fb-empty');
  var choiceTypes = ['select', 'radio', 'checkboxes'];
  var quizTypes = ['select', 'radio', 'checkboxes'];

  function csrfToken() {
    if (csrf) return csrf;
    var input = document.querySelector('input[name="_csrf_token"]');
    return input ? input.value : '';
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.classList.remove('admin-fb-status--error');
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle('admin-fb-status--error', !!isError);
  }

  function syncFieldTypePanels() {
    if (!typeSelect) return;
    var val = typeSelect.value;
    if (choiceWrap) choiceWrap.hidden = choiceTypes.indexOf(val) === -1;
    if (fileWrap) fileWrap.hidden = val !== 'file';
    if (quizWrap) quizWrap.hidden = quizTypes.indexOf(val) === -1;
    if (val === 'page_break') {
      var req = document.querySelector('#forms-add-field-form input[name="required"]');
      if (req && req.closest('label')) req.closest('label').hidden = true;
    } else {
      var req2 = document.querySelector('#forms-add-field-form input[name="required"]');
      if (req2 && req2.closest('label')) req2.closest('label').hidden = false;
    }
  }

  function setAddFieldType(typeKey, typeLabel) {
    if (!typeSelect) return;
    typeSelect.value = typeKey;
    if (typeLabelEl) typeLabelEl.textContent = typeLabel || typeKey;
    if (typeChip) {
      var icon = typeChip.querySelector('.admin-fb-type-icon');
      var paletteBtn = document.querySelector('[data-fb-palette-type="' + typeKey + '"] .admin-fb-type-icon');
      if (icon && paletteBtn) icon.innerHTML = paletteBtn.innerHTML;
    }
    if (addTitle) addTitle.textContent = 'Add ' + (typeLabel || typeKey);
    syncFieldTypePanels();
    if (addPanel) {
      addPanel.classList.add('is-active');
      addPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (labelInput) {
      labelInput.focus();
      if (!labelInput.value) labelInput.placeholder = typeLabel || 'Field label';
    }
  }

  document.querySelectorAll('[data-fb-palette-type]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('[data-fb-palette-type]').forEach(function (b) {
        b.classList.remove('is-selected');
      });
      document.querySelectorAll('.admin-fb-palette-chip').forEach(function (b) {
        b.classList.remove('is-selected');
      });
      btn.classList.add('is-selected');
      setAddFieldType(btn.getAttribute('data-fb-palette-type'), btn.getAttribute('data-fb-palette-label'));
    });
  });

  syncFieldTypePanels();

  var confirmType = document.getElementById('forms-confirmation-type');
  var msgWrap = document.getElementById('forms-confirmation-message-wrap');
  var redirWrap = document.getElementById('forms-confirmation-redirect-wrap');
  function syncConfirm() {
    if (!confirmType) return;
    var isRedirect = confirmType.value === 'redirect';
    if (msgWrap) msgWrap.hidden = isRedirect;
    if (redirWrap) redirWrap.hidden = !isRedirect;
  }
  if (confirmType) {
    confirmType.addEventListener('change', syncConfirm);
    syncConfirm();
  }

  var formType = document.getElementById('forms-form-type');
  var quizSettings = document.getElementById('forms-quiz-settings');
  if (formType && quizSettings) {
    formType.addEventListener('change', function () {
      quizSettings.hidden = formType.value !== 'quiz';
    });
  }

  function closeAllEditPanels() {
    document.querySelectorAll('.admin-fb-field').forEach(function (row) {
      var panel = row.querySelector('.admin-fb-field-panel');
      var toggle = row.querySelector('[data-fb-toggle-edit]');
      if (panel) panel.hidden = true;
      if (toggle) {
        toggle.setAttribute('aria-expanded', 'false');
        row.classList.remove('is-editing');
      }
    });
  }

  document.querySelectorAll('[data-fb-toggle-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = btn.closest('.admin-fb-field');
      if (!row) return;
      var panel = row.querySelector('.admin-fb-field-panel');
      var open = panel && panel.hidden;
      closeAllEditPanels();
      if (open && panel) {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        row.classList.add('is-editing');
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    });
  });

  document.querySelectorAll('[data-fb-close-edit]').forEach(function (btn) {
    btn.addEventListener('click', closeAllEditPanels);
  });

  if (window.location.hash && window.location.hash.indexOf('#field-') === 0) {
    var id = window.location.hash.replace('#field-', '');
    var row = document.querySelector('[data-field-id="' + id + '"]');
    if (row) {
      var toggle = row.querySelector('[data-fb-toggle-edit]');
      if (toggle) toggle.click();
    }
  }

  function ensureDropIndicator() {
    if (!dropIndicator) {
      dropIndicator = document.createElement('li');
      dropIndicator.className = 'admin-fb-drop-indicator';
      dropIndicator.setAttribute('aria-hidden', 'true');
    }
    return dropIndicator;
  }

  function getDragAfterElement(container, y) {
    var items = [].slice.call(container.querySelectorAll('[data-fb-field-row]:not(.is-dragging)'));
    var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    items.forEach(function (child) {
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        closest = { offset: offset, element: child };
      }
    });
    return closest.element;
  }

  function currentOrder() {
    if (!list) return [];
    return [].slice.call(list.querySelectorAll('[data-fb-field-row]')).map(function (el) {
      return el.getAttribute('data-field-id');
    }).filter(Boolean);
  }

  function syncEmptyState() {
    if (!list || !emptyState) return;
    var hasFields = list.querySelectorAll('[data-fb-field-row]').length > 0;
    emptyState.hidden = hasFields;
    list.hidden = !hasFields;
  }

  function saveOrder() {
    if (!reorderUrl || !list) return;
    var order = currentOrder();
    if (order.length === 0) return;

    setStatus('Saving order…', false);
    var body = new URLSearchParams();
    body.set('_csrf_token', csrfToken());
    body.set('_format', 'json');
    order.forEach(function (id) {
      body.append('field_order[]', id);
    });

    fetch(reorderUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: body.toString(),
      credentials: 'same-origin',
    })
      .then(function (res) {
        if (!res.ok) throw new Error('Reorder failed');
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          setStatus('Order saved', false);
          window.setTimeout(function () { setStatus('', false); }, 1600);
        } else {
          setStatus('Could not save order', true);
        }
      })
      .catch(function () {
        setStatus('Could not save order — refresh and try again', true);
      });
  }

  function scheduleSaveOrder() {
    if (reorderTimer) window.clearTimeout(reorderTimer);
    reorderTimer = window.setTimeout(saveOrder, 420);
  }

  function bindDragRow(row) {
    var handle = row.querySelector('[data-fb-drag-handle]');
    if (!handle) return;

    handle.addEventListener('mousedown', function () {
      row.draggable = true;
    });
    row.addEventListener('dragend', function () {
      row.draggable = false;
      row.classList.remove('is-dragging');
      dragEl = null;
      if (dropIndicator && dropIndicator.parentNode) {
        dropIndicator.parentNode.removeChild(dropIndicator);
      }
      scheduleSaveOrder();
    });
    row.addEventListener('dragstart', function (e) {
      dragEl = row;
      row.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      if (e.dataTransfer.setDragImage) {
        var ghost = row.cloneNode(true);
        ghost.classList.add('admin-fb-drag-ghost');
        ghost.style.width = row.offsetWidth + 'px';
        document.body.appendChild(ghost);
        e.dataTransfer.setDragImage(ghost, 24, 20);
        window.setTimeout(function () {
          if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
        }, 0);
      }
    });
  }

  if (list) {
    list.querySelectorAll('[data-fb-field-row]').forEach(bindDragRow);

    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragEl) return;
      var indicator = ensureDropIndicator();
      var after = getDragAfterElement(list, e.clientY);
      if (after == null) {
        list.appendChild(indicator);
        list.insertBefore(dragEl, indicator);
      } else {
        list.insertBefore(indicator, after);
        list.insertBefore(dragEl, indicator);
      }
    });

    list.addEventListener('drop', function (e) {
      e.preventDefault();
    });
  }

  syncEmptyState();
})();
