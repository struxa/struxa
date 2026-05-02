/**
 * Page editor: media library modal (grid + drag-and-drop upload) for TinyMCE / HTML textarea.
 */
(function () {
  'use strict';

  function readConfig() {
    var el = document.getElementById('admin-page-media-picker-config');
    if (!el || !el.textContent) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  /** Prefer token from a live POST form (same as server validates) over embedded JSON. */
  function csrfTokenForRequest(cfg) {
    try {
      var inputs = document.querySelectorAll('input[name="_csrf_token"]');
      for (var i = 0; i < inputs.length; i++) {
        var v = inputs[i].value;
        if (v) return v;
      }
    } catch (e) {}
    return (cfg && cfg.csrfToken) ? cfg.csrfToken : '';
  }

  var JSON_ACCEPT = { Accept: 'application/json' };

  function absUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    var o = window.location.origin.replace(/\/$/, '');
    return o + (path.charAt(0) === '/' ? path : '/' + path);
  }

  function escAttr(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function insertImageIntoEditor(taId, url) {
    if (!taId) {
      return;
    }
    var abs = absUrl(url);
    var html = '<img src="' + escAttr(abs) + '" alt="" />';
    var ta = document.getElementById(taId);
    var surface = ta ? ta.closest('.admin-page-editor-surface') : null;
    var isHtml = surface && surface.classList.contains('is-html-mode');

    if (!isHtml && typeof tinymce !== 'undefined') {
      var ed = tinymce.get(taId);
      if (ed) {
        ed.insertContent(html);
        ed.focus();
        return;
      }
    }

    if (ta) {
      var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
      var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : start;
      ta.value = ta.value.slice(0, start) + html + ta.value.slice(end);
      ta.focus();
      if (typeof ta.setSelectionRange === 'function') {
        var next = start + html.length;
        ta.setSelectionRange(next, next);
      }
    }
  }

  function renderGrid(container, images, onPick, pickVerb) {
    var verb = pickVerb || 'Insert';
    container.innerHTML = '';
    if (!images || !images.length) {
      var empty = document.createElement('p');
      empty.className = 'admin-media-modal-empty';
      empty.textContent = 'No images yet. Upload one above.';
      container.appendChild(empty);
      return;
    }
    images.forEach(function (im) {
      if (!im.url) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'admin-media-modal-tile';
      btn.setAttribute('aria-label', verb + ' ' + (im.name || 'image'));
      var img = document.createElement('img');
      img.src = absUrl(im.url);
      img.alt = '';
      img.loading = 'lazy';
      btn.appendChild(img);
      var cap = document.createElement('span');
      cap.className = 'admin-media-modal-tile-cap';
      cap.textContent = im.name || '#' + im.id;
      btn.appendChild(cap);
      btn.addEventListener('click', function () {
        onPick(im);
      });
      container.appendChild(btn);
    });
  }

  function init() {
    var cfg = readConfig();
    if (!cfg || !cfg.enabled) return;

    var taId = cfg.textareaId || 'page-content';
    var activeInsertTextareaId = taId;
    var openBtn = document.getElementById('admin-page-media-open');
    var modal = document.getElementById('admin-page-media-modal');
    var backdrop = document.getElementById('admin-page-media-backdrop');
    var closeBtn = document.getElementById('admin-page-media-close');
    var modalTitle = document.getElementById('admin-page-media-title');
    var grid = document.getElementById('admin-page-media-grid');
    var drop = document.getElementById('admin-page-media-drop');
    var fileInput = document.getElementById('admin-page-media-file');
    var statusEl = document.getElementById('admin-page-media-status');
    var refreshBtn = document.getElementById('admin-page-media-refresh');
    var fi = cfg.featuredImage || null;
    var featuredTrigger = fi && fi.triggerId ? document.getElementById(fi.triggerId) : null;

    if (!modal || !grid || !drop) return;
    var insertForBtns = document.querySelectorAll('[data-admin-media-insert-for]');
    if (!openBtn && !featuredTrigger && insertForBtns.length === 0) return;

    var images = Array.isArray(cfg.initialImages) ? cfg.initialImages.slice() : [];
    var pickMode = 'editor';
    var lastModalFocus = null;

    function setStatus(msg, isError) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.classList.toggle('admin-media-modal-status--error', !!isError);
    }

    function setFeatured(im) {
      if (!fi) return;
      var inp = document.getElementById(fi.inputId);
      var prev = document.getElementById(fi.previewId);
      var ph = document.getElementById(fi.placeholderId);
      if (inp && im && im.id != null) inp.value = String(im.id);
      if (prev && im && im.url) {
        prev.src = absUrl(im.url);
        prev.removeAttribute('hidden');
        prev.hidden = false;
      }
      if (ph) ph.hidden = true;
      closeModal();
    }

    function applyPick(im) {
      if (!im || !im.url) return;
      if (pickMode === 'featured' && fi) {
        setFeatured(im);
        return;
      }
      insertImageIntoEditor(activeInsertTextareaId, im.url);
      setStatus('Image inserted.');
      closeModal();
    }

    function wireGrid() {
      var verb = pickMode === 'featured' ? 'Set featured' : 'Insert';
      renderGrid(grid, images, applyPick, verb);
    }

    function closeModal() {
      modal.hidden = true;
      document.body.classList.remove('admin-media-modal-open');
      var ref = lastModalFocus;
      if (ref && typeof ref.focus === 'function') {
        ref.focus();
      } else if (openBtn && typeof openBtn.focus === 'function') {
        openBtn.focus();
      } else if (featuredTrigger && typeof featuredTrigger.focus === 'function') {
        featuredTrigger.focus();
      }
    }

    function openModal(mode) {
      pickMode = mode === 'featured' ? 'featured' : 'editor';
      lastModalFocus = document.activeElement;
      if (modalTitle) {
        modalTitle.textContent = pickMode === 'featured' ? 'Choose featured image' : 'Media library';
      }
      setStatus('');
      modal.hidden = false;
      document.body.classList.add('admin-media-modal-open');
      if (closeBtn && typeof closeBtn.focus === 'function') {
        closeBtn.focus();
      }
      fetch(cfg.listUrl, { credentials: 'same-origin', headers: JSON_ACCEPT })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (data && data.ok && Array.isArray(data.images)) {
            images = data.images;
          }
        })
        .catch(function () {})
        .finally(function () {
          wireGrid();
        });
    }

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        activeInsertTextareaId = taId;
        openModal('editor');
      });
    }
    insertForBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-admin-media-insert-for');
        if (id) {
          activeInsertTextareaId = id;
        }
        openModal('editor');
      });
    });
    if (featuredTrigger && !featuredTrigger.disabled) {
      featuredTrigger.addEventListener('click', function (e) {
        e.preventDefault();
        openModal('featured');
      });
    }
    if (fi && fi.clearId) {
      var clr = document.getElementById(fi.clearId);
      if (clr) {
        clr.addEventListener('click', function () {
          var inp = document.getElementById(fi.inputId);
          var prev = document.getElementById(fi.previewId);
          var ph = document.getElementById(fi.placeholderId);
          if (inp) inp.value = '';
          if (prev) {
            prev.removeAttribute('src');
            prev.hidden = true;
          }
          if (ph) ph.hidden = false;
          setStatus('');
        });
      }
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        setStatus('Refreshing…');
        fetch(cfg.listUrl, { credentials: 'same-origin', headers: JSON_ACCEPT })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data && data.ok && Array.isArray(data.images)) {
              images = data.images;
              wireGrid();
              setStatus('Library updated.');
            }
          })
          .catch(function () {
            setStatus('Could not refresh.', true);
          });
      });
    }

    function uploadFile(file) {
      if (!file || !file.type || file.type.indexOf('image/') !== 0) {
        setStatus('Please choose an image file (JPG, PNG, WebP, or GIF).', true);
        return;
      }
      var token = csrfTokenForRequest(cfg);
      if (!token) {
        setStatus('Missing CSRF token. Refresh the page and try again.', true);
        return;
      }
      setStatus('Uploading…');
      var fd = new FormData();
      fd.append('file', file, file.name);
      fd.append('_csrf_token', token);
      var headers = Object.assign({}, JSON_ACCEPT, { 'X-CSRF-Token': token });
      fetch(cfg.uploadUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: headers })
        .then(function (r) {
          return r.text().then(function (text) {
            var data = null;
            if (text) {
              try {
                data = JSON.parse(text);
              } catch (e) {
                data = null;
              }
            }
            return { ok: r.ok, status: r.status, data: data, raw: text };
          });
        })
        .then(function (res) {
          if (!res.ok || !res.data) {
            var msg =
              (res.data && (res.data.message || res.data.error)) ||
              (res.status === 403 ? 'Access denied (try refreshing the page).' : '') ||
              (res.raw && res.raw.length < 200 ? res.raw : '') ||
              'Upload failed (' + (res.status || '?') + ').';
            setStatus(msg, true);
            return;
          }
          if (!res.data.ok) {
            setStatus(res.data.error || res.data.message || 'Upload failed.', true);
            return;
          }
          if (!res.data.url) {
            setStatus('Upload saved but no URL was returned. Check media storage paths.', true);
            return;
          }
          images.unshift({ id: res.data.id, url: res.data.url, name: res.data.name || file.name });
          wireGrid();
          setStatus(
            pickMode === 'featured'
              ? 'Uploaded. Click a thumbnail to set as featured, or close.'
              : 'Uploaded. Click a thumbnail to insert, or close.'
          );
        })
        .catch(function () {
          setStatus('Upload failed (network error).', true);
        });
    }

    drop.addEventListener('click', function () {
      if (fileInput) fileInput.click();
    });

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (f) uploadFile(f);
        fileInput.value = '';
      });
    }

    function onDragOver(e) {
      e.preventDefault();
      e.stopPropagation();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      drop.classList.add('is-dragover');
    }
    ;['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, onDragOver);
    });
    drop.addEventListener('dragleave', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (!drop.contains(e.relatedTarget)) {
        drop.classList.remove('is-dragover');
      }
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      drop.classList.remove('is-dragover');
      var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) uploadFile(f);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
