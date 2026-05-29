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
      setStatus('Uploading ' + list.length + ' file' + (list.length === 1 ? '' : 's') + '…');
      var chain = Promise.resolve();
      var okCount = 0;
      list.forEach(function (file) {
        chain = chain.then(function () {
          return uploadFile(file).then(function (ok) {
            if (ok) okCount += 1;
          });
        });
      });
      chain.then(function () {
        if (okCount > 0) {
          setStatus('Uploaded ' + okCount + ' file' + (okCount === 1 ? '' : 's') + '. Reloading…');
          window.setTimeout(function () {
            window.location.reload();
          }, 600);
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
      if (bulkDelete) bulkDelete.disabled = n < 1;
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

    syncBulkUi();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
