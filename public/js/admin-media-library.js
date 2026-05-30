/**
 * Media library: drag-and-drop upload, compression toggle, copy URL.
 */
(function () {
  'use strict';

  function readConfig() {
    var el = document.getElementById('admin-media-library-config');
    if (!el || !el.textContent) return null;
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  function csrfToken() {
    try {
      var inputs = document.querySelectorAll('input[name="_csrf_token"]');
      for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].value) return inputs[i].value;
      }
    } catch (e) {}
    return '';
  }

  function absUrl(path) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    var o = window.location.origin.replace(/\/$/, '');
    return o + (path.charAt(0) === '/' ? path : '/' + path);
  }

  function init() {
    var cfg = readConfig();
    if (!cfg) return;

    var drop = document.getElementById('admin-media-upload-drop');
    var fileInput = document.getElementById('admin-media-upload-file');
    var statusEl = document.getElementById('admin-media-upload-status');
    var compressToggle = document.getElementById('admin-media-compress-toggle');
    var compressHint = document.getElementById('admin-media-compress-hint');

    function setStatus(msg, isError) {
      if (!statusEl) return;
      statusEl.hidden = !msg;
      statusEl.textContent = msg || '';
      statusEl.classList.toggle('admin-media-upload-status--error', !!isError);
      statusEl.classList.toggle('admin-media-upload-status--ok', !!msg && !isError);
    }

    function uploadFile(file) {
      if (!file || !file.type || file.type.indexOf('image/') !== 0) {
        setStatus('Please choose an image file (JPG, PNG, WebP, or GIF).', true);
        return Promise.resolve(false);
      }
      var token = csrfToken();
      if (!token) {
        setStatus('Missing CSRF token. Refresh the page and try again.', true);
        return Promise.resolve(false);
      }
      var fd = new FormData();
      fd.append('file', file, file.name);
      fd.append('_csrf_token', token);
      if (cfg.folderId) {
        fd.append('folder_id', String(cfg.folderId));
      }
      return fetch(cfg.uploadUrl, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': token },
      })
        .then(function (r) {
          return r.json().catch(function () {
            return null;
          }).then(function (data) {
            return { ok: r.ok, data: data };
          });
        })
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.ok) {
            setStatus((res.data && res.data.error) || 'Upload failed.', true);
            return false;
          }
          return true;
        })
        .catch(function () {
          setStatus('Upload failed.', true);
          return false;
        });
    }

    function uploadFiles(files) {
      if (!files || !files.length) return;
      var list = Array.prototype.slice.call(files);
      var maxMb = cfg.maxMb || 5;
      var tooBig = list.some(function (f) {
        return f.size > maxMb * 1024 * 1024;
      });
      if (tooBig) {
        setStatus('One or more files exceed the ' + maxMb + ' MB limit.', true);
        return;
      }
      var progressWrap = document.getElementById('admin-media-upload-progress');
      var progressBar = document.getElementById('admin-media-upload-progress-bar');
      var total = list.length;
      var done = 0;
      function setProgress() {
        if (!progressWrap || !progressBar) return;
        progressWrap.hidden = false;
        progressBar.style.width = Math.round((done / total) * 100) + '%';
      }
      setStatus('Uploading ' + total + ' file' + (total === 1 ? '' : 's') + '…');
      setProgress();
      var chain = Promise.resolve();
      var okCount = 0;
      list.forEach(function (file) {
        chain = chain.then(function () {
          return uploadFile(file).then(function (ok) {
            done += 1;
            setProgress();
            if (ok) okCount += 1;
          });
        });
      });
      chain.then(function () {
        if (progressWrap) progressWrap.hidden = true;
        if (okCount > 0) {
          setStatus('Uploaded ' + okCount + ' file' + (okCount === 1 ? '' : 's') + '. Reloading…');
          window.setTimeout(function () {
            window.location.reload();
          }, 600);
        } else if (done > 0) {
          setStatus('No files were uploaded.', true);
        }
      });
    }

    if (drop && fileInput) {
      drop.addEventListener('click', function () {
        fileInput.click();
      });
      drop.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          fileInput.click();
        }
      });
      fileInput.addEventListener('change', function () {
        uploadFiles(fileInput.files);
        fileInput.value = '';
      });
      ['dragenter', 'dragover'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
          e.preventDefault();
          drop.classList.add('is-dragover');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        drop.addEventListener(ev, function (e) {
          e.preventDefault();
          drop.classList.remove('is-dragover');
        });
      });
      drop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files) {
          uploadFiles(e.dataTransfer.files);
        }
      });
    }

    if (compressToggle && cfg.compressUrl) {
      var compressPanel = document.querySelector('.admin-media-compress-panel');
      var compressBadge = document.getElementById('admin-media-compress-badge');

      function setCompressUi(enabled, caps) {
        if (compressPanel) compressPanel.classList.toggle('is-on', !!enabled);
        if (compressBadge) compressBadge.textContent = enabled ? 'Active' : 'Off';
        if (compressHint) {
          if (enabled) {
            compressHint.textContent = caps && caps.webp_encode
              ? 'WebP re-encode on upload · max edge cap applied'
              : 'JPEG/PNG re-encode on upload · max edge cap applied';
          } else if (!cfg.gdAvailable) {
            /* keep server-rendered unavailable hint */
          } else {
            compressHint.textContent = 'Shrink uploads with WebP and modern codecs — toggle on to optimize new files automatically.';
          }
        }
      }

      compressToggle.addEventListener('change', function () {
        var enabled = compressToggle.checked;
        var token = csrfToken();
        var fd = new FormData();
        fd.append('enabled', enabled ? '1' : '0');
        fd.append('_csrf_token', token);
        compressToggle.disabled = true;
        fetch(cfg.compressUrl, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-CSRF-Token': token },
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data || !data.ok) {
              compressToggle.checked = !enabled;
              if (data && data.error && compressHint) {
                compressHint.textContent = data.error;
              }
              return;
            }
            setCompressUi(!!data.enabled, data.capabilities || {});
          })
          .catch(function () {
            compressToggle.checked = !enabled;
          })
          .finally(function () {
            compressToggle.disabled = !cfg.gdAvailable;
          });
      });
    }

    document.querySelectorAll('.admin-media-copy').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var path = btn.getAttribute('data-copy-url') || '';
        var url = absUrl(path);
        if (!url) return;
        function copied() {
          var prev = btn.textContent;
          btn.textContent = 'Copied';
          window.setTimeout(function () {
            btn.textContent = prev;
          }, 1400);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(copied).catch(function () {
            window.prompt('Copy URL:', url);
          });
        } else {
          window.prompt('Copy URL:', url);
        }
      });
    });

    var selectAll = document.getElementById('admin-media-select-all');
    var bulkDelete = document.getElementById('admin-media-bulk-delete');
    var bulkForm = document.getElementById('admin-media-bulk-form');
    var bulkMoveForm = document.getElementById('admin-media-bulk-move-form');
    var bulkMoveBtn = document.getElementById('admin-media-bulk-move');
    var bulkMoveSelect = document.getElementById('admin-media-bulk-move-select');
    var bulkMoveTarget = document.getElementById('admin-media-bulk-move-target');
    var bulkCount = document.getElementById('admin-media-bulk-count');
    var rowCbs = document.querySelectorAll('.admin-media-row-cb');

    function selectedCount() {
      var n = 0;
      rowCbs.forEach(function (cb) {
        if (cb.checked) n += 1;
      });
      return n;
    }

    function syncBulkUi() {
      var n = selectedCount();
      var bulkBar = document.getElementById('admin-media-bulk-bar');
      if (bulkDelete) bulkDelete.disabled = n < 1;
      if (bulkMoveBtn) bulkMoveBtn.disabled = n < 1;
      if (bulkMoveSelect) bulkMoveSelect.disabled = n < 1;
      if (bulkBar) bulkBar.classList.toggle('is-sticky', n > 0);
      if (bulkCount) {
        bulkCount.hidden = n < 1;
        bulkCount.textContent = n === 1 ? '1 selected' : n + ' selected';
      }
      if (selectAll) {
        selectAll.indeterminate = n > 0 && n < rowCbs.length;
        selectAll.checked = rowCbs.length > 0 && n === rowCbs.length;
      }
      rowCbs.forEach(function (cb) {
        var card = cb.closest('.admin-media-gallery-card');
        if (card) card.classList.toggle('is-selected', cb.checked);
      });
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        rowCbs.forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
        syncBulkUi();
      });
    }

    rowCbs.forEach(function (cb) {
      cb.addEventListener('change', syncBulkUi);
    });

    if (bulkForm && bulkDelete) {
      bulkForm.addEventListener('submit', function (e) {
        var n = selectedCount();
        if (n < 1) {
          e.preventDefault();
          return;
        }
        var msg = n === 1
          ? 'Delete this file permanently?'
          : 'Delete ' + n + ' files permanently?';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    }

    if (bulkMoveForm && bulkMoveBtn && bulkMoveSelect) {
      bulkMoveBtn.addEventListener('click', function () {
        var n = selectedCount();
        if (n < 1) return;
        var target = bulkMoveSelect.value || 'unfiled';
        if (bulkMoveTarget) bulkMoveTarget.value = target;
        bulkMoveForm.querySelectorAll('input[name="ids[]"]').forEach(function (el) {
          el.remove();
        });
        rowCbs.forEach(function (cb) {
          if (!cb.checked) return;
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'ids[]';
          input.value = cb.value;
          bulkMoveForm.appendChild(input);
        });
        var msg = n === 1 ? 'Move 1 file to the selected folder?' : 'Move ' + n + ' files to the selected folder?';
        if (window.confirm(msg)) {
          bulkMoveForm.submit();
        }
      });
    }

    syncBulkUi();

    var searchInput = document.getElementById('admin-media-search-input');
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (document.activeElement && document.activeElement.isContentEditable)) return;
      e.preventDefault();
      if (searchInput) searchInput.focus();
    });

    var lightbox = document.getElementById('admin-media-lightbox');
    var lightboxImg = document.getElementById('admin-media-lightbox-img');
    var lightboxTitle = document.getElementById('admin-media-lightbox-title');
    var lightboxEdit = document.getElementById('admin-media-lightbox-edit');
    var lightboxCopy = document.getElementById('admin-media-lightbox-copy');
    var lightboxClose = document.getElementById('admin-media-lightbox-close');
    var lightboxBackdrop = document.getElementById('admin-media-lightbox-backdrop');
    var lightboxCopyPath = '';

    function closeLightbox() {
      if (!lightbox) return;
      lightbox.hidden = true;
      document.body.classList.remove('admin-media-lightbox-open');
      if (lightboxImg) lightboxImg.removeAttribute('src');
    }

    function openLightbox(trigger) {
      if (!lightbox || !lightboxImg) return;
      var src = trigger.getAttribute('data-preview-src') || '';
      if (!src) return;
      lightboxImg.src = absUrl(src);
      lightboxImg.alt = trigger.getAttribute('data-preview-name') || '';
      if (lightboxTitle) lightboxTitle.textContent = trigger.getAttribute('data-preview-name') || 'Preview';
      if (lightboxEdit) lightboxEdit.href = trigger.getAttribute('data-preview-edit') || '#';
      lightboxCopyPath = trigger.getAttribute('data-preview-copy') || '';
      lightbox.hidden = false;
      document.body.classList.add('admin-media-lightbox-open');
      if (lightboxClose) lightboxClose.focus();
    }

    document.querySelectorAll('.admin-media-preview-trigger').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openLightbox(btn);
      });
    });

    if (lightboxCopy) {
      lightboxCopy.addEventListener('click', function () {
        var url = absUrl(lightboxCopyPath);
        if (!url) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () {
            lightboxCopy.textContent = 'Copied';
            window.setTimeout(function () {
              lightboxCopy.textContent = 'Copy URL';
            }, 1400);
          });
        } else {
          window.prompt('Copy URL:', url);
        }
      });
    }

    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    if (lightboxBackdrop) lightboxBackdrop.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lightbox && !lightbox.hidden) closeLightbox();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
