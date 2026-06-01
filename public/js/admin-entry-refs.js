/**
 * Entry links (entry_refs) picker: search, add, reorder, remove; syncs hidden JSON field.
 */
(function () {
  'use strict';

  function parseIds(raw) {
    if (!raw || typeof raw !== 'string') {
      return [];
    }
    try {
      var decoded = JSON.parse(raw);
      if (!Array.isArray(decoded)) {
        return [];
      }
      var out = [];
      decoded.forEach(function (v) {
        var n = parseInt(v, 10);
        if (n > 0 && out.indexOf(n) === -1) {
          out.push(n);
        }
      });
      return out;
    } catch (e) {
      return [];
    }
  }

  function syncHidden(root) {
    var hidden = root.querySelector('[data-entry-refs-value]');
    if (!hidden) {
      return;
    }
    var ids = [];
    root.querySelectorAll('[data-entry-refs-item]').forEach(function (li) {
      var id = parseInt(li.getAttribute('data-entry-refs-item'), 10);
      if (id > 0) {
        ids.push(id);
      }
    });
    hidden.value = ids.length ? JSON.stringify(ids) : '';
    hidden.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function renderItem(root, item) {
    var li = document.createElement('li');
    li.className = 'admin-entry-refs-item';
    li.setAttribute('data-entry-refs-item', String(item.id));
    var title = document.createElement('span');
    title.className = 'admin-entry-refs-item-title';
    if (item.edit_url) {
      var a = document.createElement('a');
      a.href = item.edit_url;
      a.className = 'admin-link';
      a.textContent = item.title || ('#' + item.id);
      a.target = '_blank';
      a.rel = 'noopener';
      title.appendChild(a);
    } else {
      title.textContent = item.title || ('#' + item.id);
    }
    var meta = document.createElement('span');
    meta.className = 'admin-entry-refs-item-meta';
    meta.textContent = (item.type_name || '') + (item.status ? ' · ' + item.status : '');
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'admin-entry-refs-remove';
    remove.setAttribute('aria-label', 'Remove link');
    remove.textContent = '×';
    remove.addEventListener('click', function () {
      li.remove();
      syncHidden(root);
    });
    li.appendChild(title);
    li.appendChild(meta);
    li.appendChild(remove);
    return li;
  }

  function canAdd(root) {
    var max = parseInt(root.getAttribute('data-max-refs'), 10) || 25;
    return root.querySelectorAll('[data-entry-refs-item]').length < max;
  }

  function addItem(root, item) {
    if (!canAdd(root)) {
      return false;
    }
    var existing = root.querySelector('[data-entry-refs-item="' + item.id + '"]');
    if (existing) {
      return false;
    }
    var list = root.querySelector('[data-entry-refs-list]');
    if (!list) {
      return false;
    }
    list.appendChild(renderItem(root, item));
    syncHidden(root);
    return true;
  }

  function bindPicker(root) {
    var url = root.getAttribute('data-picker-url');
    if (!url) {
      return;
    }
    var input = root.querySelector('[data-entry-refs-search]');
    var results = root.querySelector('[data-entry-refs-results]');
    if (!input || !results) {
      return;
    }
    var timer = null;

    function hideResults() {
      results.hidden = true;
      results.innerHTML = '';
    }

    function showResults(items) {
      results.innerHTML = '';
      if (!items.length) {
        var empty = document.createElement('p');
        empty.className = 'admin-hint';
        empty.textContent = 'No matches.';
        results.appendChild(empty);
        results.hidden = false;
        return;
      }
      items.forEach(function (item) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'admin-entry-refs-result';
        btn.textContent = item.title + ' · ' + (item.type_name || '') + ' · #' + item.id;
        btn.addEventListener('click', function () {
          if (addItem(root, item)) {
            input.value = '';
            hideResults();
          }
        });
        results.appendChild(btn);
      });
      results.hidden = false;
    }

    function fetchResults() {
      var q = input.value.trim();
      if (q.length < 1) {
        hideResults();
        return;
      }
      var params = new URLSearchParams();
      params.set('q', q);
      var typeId = root.getAttribute('data-target-type-id');
      if (typeId && typeId !== '0') {
        params.set('content_type_id', typeId);
      }
      var exclude = root.getAttribute('data-exclude-entry-id');
      if (exclude && exclude !== '0') {
        params.set('exclude_id', exclude);
      }
      fetch(url + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (data && data.ok && Array.isArray(data.items)) {
            showResults(data.items);
          } else {
            hideResults();
          }
        })
        .catch(function () {
          hideResults();
        });
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(fetchResults, 220);
    });
    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 1) {
        fetchResults();
      }
    });
    document.addEventListener('click', function (ev) {
      if (!root.contains(ev.target)) {
        hideResults();
      }
    });
  }

  function initRoot(root) {
    bindPicker(root);
    syncHidden(root);
  }

  document.querySelectorAll('[data-admin-entry-refs]').forEach(initRoot);
})();
