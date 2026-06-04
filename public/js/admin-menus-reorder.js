(function () {
  'use strict';

  var list = document.getElementById('admin-menu-sort-list');
  var form = document.getElementById('menu-reorder-form');
  if (!list || !form) {
    return;
  }

  var dragItem = null;

  function items() {
    return Array.prototype.slice.call(list.querySelectorAll('.admin-menu-sort-item'));
  }

  function syncOrderInputs() {
    form.querySelectorAll('input[name^="order["]').forEach(function (el) {
      el.remove();
    });
    items().forEach(function (li, index) {
      var id = li.getAttribute('data-item-id');
      if (!id) {
        return;
      }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'order[' + id + ']';
      input.value = String(index);
      form.appendChild(input);
    });
  }

  function clearDropTargets() {
    items().forEach(function (li) {
      li.classList.remove('is-drag-over');
    });
  }

  items().forEach(function (li) {
    li.setAttribute('draggable', 'true');

    li.addEventListener('dragstart', function (e) {
      dragItem = li;
      li.classList.add('is-dragging');
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', li.getAttribute('data-item-id') || '');
      }
    });

    li.addEventListener('dragend', function () {
      li.classList.remove('is-dragging');
      clearDropTargets();
      dragItem = null;
      syncOrderInputs();
    });

    li.addEventListener('dragover', function (e) {
      e.preventDefault();
      if (!dragItem || dragItem === li) {
        return;
      }
      clearDropTargets();
      li.classList.add('is-drag-over');
      var rect = li.getBoundingClientRect();
      var before = e.clientY < rect.top + rect.height / 2;
      if (before) {
        list.insertBefore(dragItem, li);
      } else {
        list.insertBefore(dragItem, li.nextSibling);
      }
    });

    li.addEventListener('drop', function (e) {
      e.preventDefault();
      clearDropTargets();
      syncOrderInputs();
    });
  });

  var saveBtn = document.getElementById('admin-menu-sort-save');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      syncOrderInputs();
      form.submit();
    });
  }

  syncOrderInputs();
})();
