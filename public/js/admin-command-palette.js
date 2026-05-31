(function () {
  "use strict";

  var trigger = document.getElementById("admin-cmd-trigger");
  var dialog = document.getElementById("admin-cmd-palette");
  if (!dialog) return;

  var input = document.getElementById("admin-cmd-input");
  var list = document.getElementById("admin-cmd-results");
  var backdrop = dialog.querySelector(".admin-cmd-backdrop");
  var navItems = [];
  var remoteItems = [];
  var activeIndex = 0;
  var suggestTimer = null;
  var suggestRequestId = 0;
  var suggestUrl = dialog.getAttribute("data-suggest-url") || "";
  var searchUrl = dialog.getAttribute("data-search-url") || "";

  var isMac = typeof navigator !== "undefined" && /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent);

  function escapeHtml(text) {
    var el = document.createElement("span");
    el.textContent = text;
    return el.innerHTML;
  }

  function collectNavItems() {
    navItems = [];
    var nav = document.getElementById("admin-primary-nav");
    if (nav) {
      nav.querySelectorAll("a[href]").forEach(function (link) {
        var href = link.getAttribute("href");
        if (!href || href === "#") return;
        var label = (link.textContent || "").replace(/\s+/g, " ").trim();
        if (!label) return;
        var group = "Navigation";
        var groupEl = link.closest(".admin-nav-group");
        if (groupEl) {
          var summary = groupEl.querySelector(":scope > summary.admin-nav-group-title");
          if (summary) {
            group = (summary.textContent || "").trim() || group;
          }
        } else if (link.classList.contains("admin-nav-pin")) {
          group = "Pinned";
        }
        navItems.push({ label: label, href: href, group: group });
      });
    }

    document.querySelectorAll(".admin-create-menu-panel a[href]").forEach(function (link) {
      var href = link.getAttribute("href");
      if (!href) return;
      navItems.push({
        label: link.textContent.trim(),
        href: href,
        group: "Create",
      });
    });

    var seen = {};
    navItems = navItems.filter(function (item) {
      if (seen[item.href]) return false;
      seen[item.href] = true;
      return true;
    });
  }

  function filterNavItems(query) {
    var q = (query || "").toLowerCase().trim();
    if (!q) return navItems.slice(0, 10);
    return navItems
      .filter(function (item) {
        return (item.label + " " + item.group).toLowerCase().indexOf(q) !== -1;
      })
      .slice(0, 10);
  }

  function mergedItems(query) {
    var q = (query || "").trim();
    if (q.length >= 2 && remoteItems.length) {
      return remoteItems.slice(0, 14);
    }
    if (q.length >= 2 && suggestUrl) {
      return [];
    }
    return filterNavItems(q);
  }

  function scheduleSuggest(query) {
    if (!suggestUrl) return;
    var q = (query || "").trim();
    if (suggestTimer) {
      clearTimeout(suggestTimer);
      suggestTimer = null;
    }
    if (q.length < 2) {
      remoteItems = [];
      render(query);
      return;
    }
    var requestId = ++suggestRequestId;
    suggestTimer = setTimeout(function () {
      suggestTimer = null;
      fetch(suggestUrl + "?q=" + encodeURIComponent(q), {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      })
        .then(function (res) {
          return res.ok ? res.json() : { items: [] };
        })
        .then(function (data) {
          if (requestId !== suggestRequestId) return;
          remoteItems = Array.isArray(data.items) ? data.items : [];
          if (remoteItems.length === 0 && searchUrl) {
            remoteItems.push({
              label: 'Search for "' + q + '"',
              href: searchUrl + "?q=" + encodeURIComponent(q),
              group: "Search",
            });
          }
          render(query);
        })
        .catch(function () {
          if (requestId !== suggestRequestId) return;
          remoteItems = searchUrl
            ? [{ label: 'Search for "' + q + '"', href: searchUrl + "?q=" + encodeURIComponent(q), group: "Search" }]
            : [];
          render(query);
        });
    }, 180);
  }

  function render(query) {
    var results = mergedItems(query);
    activeIndex = 0;
    list.innerHTML = "";

    if (!results.length) {
      var q = (query || "").trim();
      var empty = document.createElement("li");
      empty.className = "admin-cmd-empty";
      if (q.length >= 2 && suggestUrl) {
        empty.textContent = "Searching…";
      } else {
        empty.textContent = "No matches — try another keyword.";
      }
      list.appendChild(empty);
      return;
    }

    results.forEach(function (item, index) {
      var li = document.createElement("li");
      li.className = "admin-cmd-item" + (index === 0 ? " is-active" : "");
      li.setAttribute("role", "option");
      li.setAttribute("aria-selected", index === 0 ? "true" : "false");
      li.dataset.href = item.href;
      li.innerHTML =
        '<span class="admin-cmd-item-main">' +
        '<span class="admin-cmd-item-label">' +
        escapeHtml(item.label) +
        "</span>" +
        '<span class="admin-cmd-item-meta">' +
        escapeHtml(item.group + (item.meta ? " · " + item.meta : "")) +
        "</span>" +
        "</span>" +
        '<svg class="admin-cmd-item-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>';
      list.appendChild(li);
    });
  }

  function setActive(index) {
    var nodes = list.querySelectorAll(".admin-cmd-item");
    if (!nodes.length) return;
    activeIndex = Math.max(0, Math.min(index, nodes.length - 1));
    nodes.forEach(function (node, i) {
      var on = i === activeIndex;
      node.classList.toggle("is-active", on);
      node.setAttribute("aria-selected", on ? "true" : "false");
    });
    nodes[activeIndex].scrollIntoView({ block: "nearest" });
  }

  function openPalette() {
    collectNavItems();
    remoteItems = [];
    suggestRequestId += 1;
    render("");
    dialog.hidden = false;
    dialog.classList.add("is-open");
    document.body.classList.add("admin-cmd-open");
    if (input) {
      input.value = "";
      input.focus();
    }
  }

  function closePalette() {
    dialog.hidden = true;
    dialog.classList.remove("is-open");
    document.body.classList.remove("admin-cmd-open");
    remoteItems = [];
    if (suggestTimer) {
      clearTimeout(suggestTimer);
      suggestTimer = null;
    }
    if (trigger) trigger.focus();
  }

  function goActive() {
    var nodes = list.querySelectorAll(".admin-cmd-item");
    var node = nodes[activeIndex];
    if (node && node.dataset.href) {
      window.location.href = node.dataset.href;
    }
  }

  if (trigger) {
    trigger.addEventListener("click", openPalette);
  }

  var sidebarJump = document.getElementById("admin-sidebar-jump");
  if (sidebarJump) {
    sidebarJump.addEventListener("click", openPalette);
  }

  if (backdrop) {
    backdrop.addEventListener("click", closePalette);
  }

  if (input) {
    input.addEventListener("input", function () {
      remoteItems = [];
      render(input.value);
      scheduleSuggest(input.value);
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        setActive(activeIndex + 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        setActive(activeIndex - 1);
      } else if (event.key === "Enter") {
        event.preventDefault();
        goActive();
      } else if (event.key === "Escape") {
        event.preventDefault();
        closePalette();
      }
    });
  }

  list.addEventListener("mousemove", function (event) {
    var item = event.target.closest(".admin-cmd-item");
    if (!item) return;
    var nodes = list.querySelectorAll(".admin-cmd-item");
    activeIndex = Array.prototype.indexOf.call(nodes, item);
    setActive(activeIndex);
  });

  list.addEventListener("click", function (event) {
    var item = event.target.closest(".admin-cmd-item");
    if (item && item.dataset.href) {
      window.location.href = item.dataset.href;
    }
  });

  document.addEventListener("keydown", function (event) {
    var mod = isMac ? event.metaKey : event.ctrlKey;
    if (mod && (event.key === "k" || event.key === "K")) {
      event.preventDefault();
      if (dialog.classList.contains("is-open")) {
        closePalette();
      } else {
        openPalette();
      }
      return;
    }
    if (event.key === "Escape" && dialog.classList.contains("is-open")) {
      event.preventDefault();
      closePalette();
    }
  });

  var modKbd = document.querySelector(".admin-cmd-kbd--mod");
  if (modKbd && !isMac) {
    modKbd.textContent = "Ctrl+K";
  }

  var footMod = document.querySelector(".admin-cmd-foot-mod .admin-cmd-kbd--mod");
  if (footMod && !isMac) {
    footMod.textContent = "Ctrl";
  }

  var sidebarJumpKbd = document.querySelector(".admin-sidebar-jump-kbd");
  if (sidebarJumpKbd && !isMac) {
    sidebarJumpKbd.textContent = "Ctrl+K";
  }
})();
