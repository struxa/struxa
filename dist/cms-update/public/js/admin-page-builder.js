/**
 * Structured page builder: drag to reorder, then submit "Save order" to persist.
 */
(function () {
  var list = document.getElementById("page-builder-list");
  var form = document.getElementById("page-builder-reorder-form");
  if (!list || !form) return;

  var dragEl = null;

  function getDragAfterElement(container, y) {
    var draggable = [].slice.call(container.querySelectorAll("[data-section-row]:not(.is-dragging)"));
    var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
    draggable.forEach(function (child) {
      var box = child.getBoundingClientRect();
      var offset = y - box.top - box.height / 2;
      if (offset < 0 && offset > closest.offset) {
        closest = { offset: offset, element: child };
      }
    });
    return closest.element;
  }

  list.querySelectorAll("[data-section-row]").forEach(function (row) {
    row.setAttribute("draggable", "true");
    row.addEventListener("dragstart", function () {
      dragEl = row;
      row.classList.add("is-dragging");
    });
    row.addEventListener("dragend", function () {
      row.classList.remove("is-dragging");
      dragEl = null;
    });
  });

  list.addEventListener("dragover", function (e) {
    e.preventDefault();
    if (!dragEl) return;
    var after = getDragAfterElement(list, e.clientY);
    if (after == null) {
      list.appendChild(dragEl);
    } else {
      list.insertBefore(dragEl, after);
    }
  });

  form.addEventListener("submit", function () {
    form.querySelectorAll('input[name="order[]"]').forEach(function (n) {
      n.remove();
    });
    list.querySelectorAll("[data-section-row]").forEach(function (el) {
      var id = el.getAttribute("data-section-row");
      if (!id) return;
      var input = document.createElement("input");
      input.type = "hidden";
      input.name = "order[]";
      input.value = id;
      form.appendChild(input);
    });
  });
})();
