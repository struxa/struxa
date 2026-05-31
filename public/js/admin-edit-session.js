/**
 * Edit session: heartbeat lock + server autosave for page/entry forms.
 */
(function () {
  'use strict';

  var cfgEl = document.getElementById('admin-edit-session-config');
  if (!cfgEl) return;

  var config;
  try {
    config = JSON.parse(cfgEl.textContent || '{}');
  } catch (e) {
    return;
  }

  if (!config.enabled || !config.sessionUrl) return;

  var form = document.getElementById(config.formId || '');
  if (!form) return;

  var storageKey =
    'struxa_edit_lock_' + config.subjectType + '_' + String(config.subjectId);
  var lockToken = sessionStorage.getItem(storageKey);
  if (!lockToken) {
    lockToken =
      typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : 'lock-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    sessionStorage.setItem(storageKey, lockToken);
  }

  var blocked = false;
  var dirty = false;
  var lastAutosaveAt = 0;
  var heartbeatMs = config.heartbeatMs || 25000;
  var bannerEl = document.getElementById('admin-edit-lock-banner');
  var autosaveBannerEl = document.getElementById('admin-edit-autosave-banner');
  var takeoverBtn = document.getElementById('admin-edit-lock-takeover');
  var restoreBtn = document.getElementById('admin-edit-autosave-restore');
  var dismissAutosaveBtn = document.getElementById('admin-edit-autosave-dismiss');

  function markDirty() {
    dirty = true;
  }

  function collectPayload() {
    var out = {};
    var els = form.querySelectorAll('input, textarea, select');
    els.forEach(function (el) {
      if (!el.name || el.disabled) return;
      if (el.type === 'hidden' && el.name === '_csrf_token') return;
      if (el.type === 'submit' || el.type === 'button') return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.type === 'radio' && !el.checked) return;
        out[el.name] = el.type === 'checkbox' ? (el.checked ? (el.value || '1') : '') : el.value;
        return;
      }
      out[el.name] = el.value;
    });

    if (typeof tinymce !== 'undefined' && tinymce.editors) {
      tinymce.editors.forEach(function (ed) {
        var el = ed.getElement && ed.getElement();
        if (el && el.name) {
          out[el.name] = ed.getContent();
        }
      });
    }

    return out;
  }

  function applyPayload(payload) {
    if (!payload || typeof payload !== 'object') return;

    Object.keys(payload).forEach(function (name) {
      var val = payload[name];
      var fields = form.querySelectorAll('[name="' + name.replace(/"/g, '\\"') + '"]');
      if (!fields.length) return;

      fields.forEach(function (field) {
        if (field.type === 'checkbox') {
          field.checked = val === '1' || val === 'on' || val === true || val === field.value;
        } else if (field.type === 'radio') {
          field.checked = String(field.value) === String(val);
        } else {
          field.value = val == null ? '' : String(val);
        }
      });

      if (typeof tinymce !== 'undefined') {
        var ed = tinymce.get(fields[0].id);
        if (ed) {
          ed.setContent(val == null ? '' : String(val));
        }
      }
    });

    form.dispatchEvent(new Event('input', { bubbles: true }));
    markDirty();
  }

  function setBlocked(holder) {
    blocked = true;
    form.querySelectorAll('input, textarea, select, button').forEach(function (el) {
      if (el.id === 'admin-edit-lock-takeover') return;
      if (el.type === 'hidden') return;
      el.disabled = true;
    });
    if (bannerEl) {
      bannerEl.hidden = false;
      var who = holder && (holder.display_name || holder.email) ? holder.display_name || holder.email : 'Someone else';
      bannerEl.querySelector('[data-edit-lock-who]').textContent = who;
    }
  }

  function clearBlocked() {
    blocked = false;
    form.querySelectorAll('input, textarea, select, button').forEach(function (el) {
      if (el.id === 'admin-edit-lock-takeover') return;
      if (el.type === 'hidden') return;
      el.disabled = false;
    });
    if (bannerEl) bannerEl.hidden = true;
  }

  function postSession(action, includePayload) {
    var body = {
      _csrf_token: config.csrfToken,
      subject_type: config.subjectType,
      subject_id: config.subjectId,
      lock_token: lockToken,
      action: action,
    };
    if (includePayload && dirty) {
      body.payload = collectPayload();
    }

    return fetch(config.sessionUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': config.csrfToken,
      },
      body: JSON.stringify(body),
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json().then(function (data) {
        return { res: res, data: data };
      });
    });
  }

  function handleSessionResult(data, res) {
    if (data.blocked) {
      setBlocked(data.holder);
      return;
    }
    clearBlocked();
    if (data.autosave_saved) {
      lastAutosaveAt = Date.now();
      dirty = false;
    }
  }

  function heartbeat(includePayload) {
    if (blocked) return Promise.resolve();
    return postSession('heartbeat', includePayload)
      .then(function (r) {
        handleSessionResult(r.data, r.res);
      })
      .catch(function () {});
  }

  function acquireLock() {
    return postSession('acquire', false).then(function (r) {
      handleSessionResult(r.data, r.res);
      if (r.data.blocked) return;
      return heartbeat(false);
    });
  }

  function releaseLock() {
    var body = JSON.stringify({
      _csrf_token: config.csrfToken,
      subject_type: config.subjectType,
      subject_id: config.subjectId,
      lock_token: lockToken,
      action: 'release',
    });
    if (navigator.sendBeacon) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(config.sessionUrl, blob);
    } else {
      postSession('release', false);
    }
    sessionStorage.removeItem(storageKey);
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);

  form.addEventListener('submit', function () {
    releaseLock();
  });

  window.addEventListener('beforeunload', function () {
    if (!blocked) releaseLock();
  });

  if (takeoverBtn) {
    takeoverBtn.addEventListener('click', function () {
      postSession('takeover', false).then(function (r) {
        if (!r.data.blocked) {
          clearBlocked();
          heartbeat(false);
        }
      });
    });
  }

  if (restoreBtn && config.autosavePayload) {
    restoreBtn.addEventListener('click', function () {
      applyPayload(config.autosavePayload);
      if (autosaveBannerEl) autosaveBannerEl.hidden = true;
    });
  }

  if (dismissAutosaveBtn && autosaveBannerEl) {
    dismissAutosaveBtn.addEventListener('click', function () {
      autosaveBannerEl.hidden = true;
    });
  }

  if (config.initialBlocked && config.initialHolder) {
    setBlocked(config.initialHolder);
  }

  acquireLock();
  setInterval(function () {
    heartbeat(true);
  }, heartbeatMs);

  if (typeof tinymce !== 'undefined') {
    var bindEditor = function (ed) {
      ed.on('Change Undo Redo SetContent', function (e) {
        if (e && e.type === 'SetContent' && !ed._struxaUserEdit) return;
        markDirty();
      });
    };
    if (!window._struxaEditSessionTinyBind) {
      window._struxaEditSessionTinyBind = true;
      tinymce.on('AddEditor', function (ev) {
        bindEditor(ev.editor);
      });
    }
    if (tinymce.editors) tinymce.editors.forEach(bindEditor);
  }
})();
