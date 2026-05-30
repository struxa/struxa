/**
 * Maintenance: batch image library optimization with progress.
 */
(function () {
  'use strict';

  var root = document.getElementById('admin-maintenance-compress');
  if (!root) return;

  var batchUrl = root.getAttribute('data-batch-url') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var totalImages = parseInt(root.getAttribute('data-image-count') || '0', 10) || 0;
  var statusEl = document.getElementById('admin-maintenance-compress-status');
  var barWrap = root.querySelector('.admin-maintenance-compress-bar');
  var barFill = document.getElementById('admin-maintenance-compress-bar-fill');
  var startBtn = document.getElementById('admin-maintenance-compress-start');
  var stopBtn = document.getElementById('admin-maintenance-compress-stop');

  var running = false;
  var stopRequested = false;
  var afterId = 0;
  var processedTotal = 0;
  var optimizedTotal = 0;
  var savedTotal = 0;

  function formatBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
  }

  function setProgress() {
    if (!barWrap || !barFill) return;
    barWrap.hidden = false;
    var pct = totalImages > 0 ? Math.min(100, Math.round((processedTotal / totalImages) * 100)) : 0;
    barFill.style.width = pct + '%';
  }

  function runBatch() {
    if (!running || stopRequested) {
      running = false;
      if (startBtn) startBtn.disabled = false;
      if (stopBtn) stopBtn.hidden = true;
      setStatus(
        'Finished — processed ' +
          processedTotal +
          ', optimized ' +
          optimizedTotal +
          ', saved ' +
          formatBytes(savedTotal) +
          '.'
      );
      return;
    }

    var body = new URLSearchParams();
    body.set('after_id', String(afterId));
    body.set('limit', '20');
    body.set('_csrf_token', csrf);

    fetch(batchUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: body.toString(),
      credentials: 'same-origin',
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (result) {
        var data = result.data || {};
        if (!result.ok || data.ok !== true) {
          running = false;
          if (startBtn) startBtn.disabled = false;
          if (stopBtn) stopBtn.hidden = true;
          setStatus('Error: ' + (data.error || 'Batch failed.'));
          return;
        }

        processedTotal += data.processed || 0;
        optimizedTotal += data.optimized || 0;
        savedTotal += data.bytes_saved || 0;
        afterId = data.next_after_id || afterId;
        setProgress();
        setStatus(
          'Optimizing… ' +
            processedTotal +
            (totalImages > 0 ? ' / ~' + totalImages : '') +
            ' processed, ' +
            optimizedTotal +
            ' shrunk, ' +
            formatBytes(savedTotal) +
            ' saved.'
        );

        if (data.done || stopRequested) {
          running = false;
          if (startBtn) startBtn.disabled = false;
          if (stopBtn) stopBtn.hidden = true;
          setStatus(
            (stopRequested ? 'Stopped — ' : 'Complete — ') +
              processedTotal +
              ' processed, ' +
              optimizedTotal +
              ' optimized, ' +
              formatBytes(savedTotal) +
              ' saved.'
          );
          return;
        }

        window.setTimeout(runBatch, 80);
      })
      .catch(function () {
        running = false;
        if (startBtn) startBtn.disabled = false;
        if (stopBtn) stopBtn.hidden = true;
        setStatus('Network error — try again.');
      });
  }

  if (startBtn) {
    startBtn.addEventListener('click', function () {
      if (running) return;
      if (totalImages === 0) {
        setStatus('No images in the library.');
        return;
      }
      if (!confirm('Optimize all library images in batches?\n\nExisting files may be re-encoded as WebP. This can take a few minutes.')) {
        return;
      }
      running = true;
      stopRequested = false;
      afterId = 0;
      processedTotal = 0;
      optimizedTotal = 0;
      savedTotal = 0;
      startBtn.disabled = true;
      if (stopBtn) stopBtn.hidden = false;
      setStatus('Starting…');
      runBatch();
    });
  }

  if (stopBtn) {
    stopBtn.addEventListener('click', function () {
      stopRequested = true;
      stopBtn.hidden = true;
      setStatus('Stopping after current batch…');
    });
  }
})();
