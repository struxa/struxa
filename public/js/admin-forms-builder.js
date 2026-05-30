(function () {
  'use strict';

  var list = document.getElementById('forms-field-list');
  if (list) {
    var dragEl = null;
    list.addEventListener('dragstart', function (e) {
      var li = e.target.closest('.forms-field-item');
      if (!li) return;
      dragEl = li;
      li.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', function () {
      if (dragEl) dragEl.classList.remove('is-dragging');
      dragEl = null;
      autoSubmitReorder();
    });
    list.addEventListener('dragover', function (e) {
      e.preventDefault();
      var li = e.target.closest('.forms-field-item');
      if (!li || !dragEl || li === dragEl) return;
      var rect = li.getBoundingClientRect();
      var after = e.clientY > rect.top + rect.height / 2;
      list.insertBefore(dragEl, after ? li.nextSibling : li);
    });

    function autoSubmitReorder() {
      var formId = list.getAttribute('data-reorder-form');
      var form = formId ? document.getElementById(formId) : null;
      if (form) form.submit();
    }
  }

  var typeSelect = document.getElementById('forms-new-field-type');
  var choiceWrap = document.getElementById('forms-choice-options');
  var choiceTypes = ['select', 'radio', 'checkboxes'];
  function syncChoiceOptions() {
    if (!typeSelect || !choiceWrap) return;
    choiceWrap.hidden = choiceTypes.indexOf(typeSelect.value) === -1;
  }
  if (typeSelect) {
    typeSelect.addEventListener('change', syncChoiceOptions);
    syncChoiceOptions();
  }

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
})();
