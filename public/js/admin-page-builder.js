/**
 * Page builder: drag-reorder with auto-save, visual block palette modal, inline section drawer.
 */
(function () {
  var root = document.getElementById("admin-page-builder");
  var list = document.getElementById("page-builder-list");
  if (!root) return;

  var builderUrl = root.getAttribute("data-builder-url") || "";
  var csrf = root.getAttribute("data-csrf") || "";
  var statusEl = document.getElementById("admin-pb-status");
  var palette = document.getElementById("admin-pb-palette");
  var drawer = document.getElementById("admin-pb-drawer");
  var drawerPanel = document.getElementById("admin-pb-drawer-panel");
  var activeEditRow = null;
  var dragEl = null;
  var reorderTimer = null;

  function setStatus(msg, isError) {
    if (!statusEl) return;
    if (!msg) {
      statusEl.hidden = true;
      statusEl.textContent = "";
      statusEl.classList.remove("admin-pb-status--error");
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = msg;
    statusEl.classList.toggle("admin-pb-status--error", !!isError);
  }

  function openPalette() {
    if (!palette) return;
    palette.hidden = false;
    document.body.classList.add("admin-pb-palette-open");
    var first = palette.querySelector(".admin-pb-palette-card");
    if (first) first.focus();
  }

  function closePalette() {
    if (!palette) return;
    palette.hidden = true;
    document.body.classList.remove("admin-pb-palette-open");
  }

  function drawerLoadingHtml() {
    return '<p class="admin-pb-drawer-loading">Loading…</p>';
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.hidden = true;
    document.body.classList.remove("admin-pb-drawer-open");
    drawer.classList.remove("admin-pb-drawer--busy");
    activeEditRow = null;
    if (drawerPanel) {
      drawerPanel.innerHTML = drawerLoadingHtml();
    }
  }

  function bindDrawerPanel() {
    if (!drawerPanel) return;
    drawerPanel.querySelectorAll("[data-admin-pb-drawer-close]").forEach(function (btn) {
      btn.addEventListener("click", closeDrawer);
    });
    var form = drawerPanel.querySelector("#section-edit-form");
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      saveDrawerForm(form);
    });
  }

  function openDrawer(url, rowEl) {
    if (!drawer || !drawerPanel || !url) return;
    activeEditRow = rowEl || null;
    drawer.hidden = false;
    document.body.classList.add("admin-pb-drawer-open");
    drawer.classList.add("admin-pb-drawer--busy");
    drawerPanel.innerHTML = drawerLoadingHtml();

    var loadUrl = url + (url.indexOf("?") >= 0 ? "&" : "?") + "_format=partial";
    fetch(loadUrl, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "text/html" },
    })
      .then(function (res) {
        if (!res.ok) throw new Error("Load failed");
        return res.text();
      })
      .then(function (html) {
        drawer.classList.remove("admin-pb-drawer--busy");
        drawerPanel.innerHTML = html;
        bindDrawerPanel();
        var title = drawerPanel.querySelector(".admin-pb-drawer-title");
        if (title) title.id = "admin-pb-drawer-title";
      })
      .catch(function () {
        drawer.classList.remove("admin-pb-drawer--busy");
        drawerPanel.innerHTML =
          '<p class="admin-pb-drawer-loading">Could not load the editor. <button type="button" class="admin-btn-ghost" data-admin-pb-drawer-close>Close</button></p>';
        bindDrawerPanel();
        setStatus("Could not open block editor.", true);
      });
  }

  function saveDrawerForm(form) {
    if (!drawer) return;
    drawer.classList.add("admin-pb-drawer--busy");
    setStatus("Saving block…", false);

    var action = form.getAttribute("action") || "";
    var saveUrl = action.indexOf("?_format=") >= 0 ? action : action + "?_format=json";
    var body = new FormData(form);
    if (csrf && !body.get("_csrf_token")) {
      body.append("_csrf_token", csrf);
    }

    fetch(saveUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      body: body,
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (result) {
        drawer.classList.remove("admin-pb-drawer--busy");
        var data = result.data || {};
        if (data.ok) {
          if (activeEditRow) {
            var previewEl = activeEditRow.querySelector(".admin-pb-row-preview");
            if (data.preview) {
              if (previewEl) {
                previewEl.textContent = data.preview;
              } else {
                var bodyEl = activeEditRow.querySelector(".admin-pb-row-body");
                if (bodyEl) {
                  var p = document.createElement("p");
                  p.className = "admin-pb-row-preview";
                  p.textContent = data.preview;
                  bodyEl.appendChild(p);
                }
              }
            } else if (previewEl) {
              previewEl.remove();
            }
          }
          closeDrawer();
          setStatus("Block saved.", false);
          window.setTimeout(function () {
            setStatus("", false);
          }, 1800);
          return;
        }
        if (data.html && drawerPanel) {
          drawerPanel.innerHTML = data.html;
          bindDrawerPanel();
          setStatus("Fix the highlighted fields.", true);
          return;
        }
        setStatus("Could not save block.", true);
      })
      .catch(function () {
        drawer.classList.remove("admin-pb-drawer--busy");
        setStatus("Could not save block — try again.", true);
      });
  }

  var openBtn = document.getElementById("admin-pb-open-palette");
  if (openBtn) openBtn.addEventListener("click", openPalette);
  document.querySelectorAll("[data-admin-pb-open-palette]").forEach(function (btn) {
    btn.addEventListener("click", openPalette);
  });
  document.getElementById("admin-pb-palette-close")?.addEventListener("click", closePalette);
  document.getElementById("admin-pb-palette-dismiss")?.addEventListener("click", closePalette);

  root.querySelectorAll("[data-admin-pb-edit]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var url = btn.getAttribute("data-admin-pb-edit-url");
      var row = btn.closest("[data-section-row]");
      openDrawer(url, row);
    });
  });

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    if (palette && !palette.hidden) closePalette();
    if (drawer && !drawer.hidden) closeDrawer();
  });

  if (!list) return;

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

  function currentOrder() {
    return [].slice
      .call(list.querySelectorAll("[data-section-row]"))
      .map(function (el) {
        return el.getAttribute("data-section-row");
      })
      .filter(Boolean);
  }

  function saveOrder() {
    if (!builderUrl) return;
    var order = currentOrder();
    if (order.length === 0) return;

    setStatus("Saving order…", false);
    var body = new URLSearchParams();
    body.set("_action", "reorder");
    body.set("_format", "json");
    body.set("_csrf_token", csrf);
    order.forEach(function (id) {
      body.append("order[]", id);
    });

    fetch(builderUrl, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
      body: body.toString(),
      credentials: "same-origin",
    })
      .then(function (res) {
        if (!res.ok) throw new Error("Reorder failed");
        return res.json();
      })
      .then(function (data) {
        if (data && data.ok) {
          setStatus("Order saved.", false);
          window.setTimeout(function () {
            setStatus("", false);
          }, 1800);
        } else {
          setStatus("Could not save order.", true);
        }
      })
      .catch(function () {
        setStatus("Could not save order — refresh and try again.", true);
      });
  }

  function scheduleSaveOrder() {
    if (reorderTimer) window.clearTimeout(reorderTimer);
    reorderTimer = window.setTimeout(saveOrder, 450);
  }

  list.querySelectorAll("[data-section-row]").forEach(function (row) {
    row.addEventListener("dragstart", function () {
      dragEl = row;
      row.classList.add("is-dragging");
    });
    row.addEventListener("dragend", function () {
      row.classList.remove("is-dragging");
      dragEl = null;
      scheduleSaveOrder();
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
})();
