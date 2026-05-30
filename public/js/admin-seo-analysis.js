(function () {
  'use strict';

  function csrfToken() {
    var root = document.getElementById('admin-seo-analysis-root');
    if (root && root.getAttribute('data-csrf')) return root.getAttribute('data-csrf');
    var inputs = document.querySelectorAll('input[name="_csrf_token"]');
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].value) return inputs[i].value;
    }
    return '';
  }

  function el(id) {
    return document.getElementById(id);
  }

  function titleInput() {
    return el('page-title') || el('ent-title') || el('tm-name') || el('seo-suite-title');
  }

  function slugInput() {
    return el('page-slug') || el('ent-slug') || el('tm-slug');
  }

  function seoTitleInput() {
    return el('page-seo-title') || el('ent-seo-title') || el('seo-suite-title');
  }

  function seoDescInput() {
    return el('page-seo-desc') || el('ent-seo-desc') || el('seo-suite-desc');
  }

  function contentInput() {
    return el('page-content') || null;
  }

  function focusInput() {
    return el('seo-focus-keyphrase');
  }

  function scoreClass(n) {
    if (n >= 70) return 'is-good';
    if (n >= 40) return 'is-ok';
    return 'is-bad';
  }

  function renderChecks(listEl, checks) {
    if (!listEl) return;
    listEl.innerHTML = '';
    (checks || []).forEach(function (c) {
      var li = document.createElement('li');
      li.className = 'admin-seo-check admin-seo-check--' + (c.status || 'na');
      li.innerHTML = '<span class="admin-seo-check-dot" aria-hidden="true"></span><span><strong>' + escapeHtml(c.label || '') + '</strong> — ' + escapeHtml(c.message || '') + '</span>';
      listEl.appendChild(li);
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function updateSerpPreview() {
    var root = el('admin-seo-analysis-root');
    if (!root) return;
    var site = (root.getAttribute('data-site-url') || 'https://example.com').replace(/^https?:\/\//, '').replace(/\/$/, '');
    var prefix = root.getAttribute('data-path-prefix') || '/';
    var slug = slugInput() ? slugInput().value.trim() : '';
    var title = '';
    if (seoTitleInput() && seoTitleInput().value.trim()) title = seoTitleInput().value.trim();
    else if (titleInput() && titleInput().value.trim()) title = titleInput().value.trim();
    var desc = '';
    if (seoDescInput() && seoDescInput().value.trim()) desc = seoDescInput().value.trim();
    var siteEl = el('seo-serp-site');
    var crumbEl = el('seo-serp-breadcrumb');
    var titleEl = el('seo-serp-title');
    var descEl = el('seo-serp-desc');
    if (siteEl) siteEl.textContent = site;
    if (crumbEl) crumbEl.textContent = 'Home › ' + (slug || '…');
    if (titleEl) titleEl.textContent = title || 'Page title preview';
    if (descEl) descEl.textContent = desc || 'Meta description preview will appear here as you type.';
  }

  function scoreStatusLabel(n) {
    if (n >= 70) return 'Good';
    if (n >= 40) return 'OK';
    return 'Needs work';
  }

  function setScore(kind, value) {
    var valEl = el(kind === 'seo' ? 'seo-score-value' : 'readability-score-value');
    var statusEl = el(kind === 'seo' ? 'seo-score-status' : 'readability-score-status');
    var card = document.querySelector('.admin-seo-score-card[data-score-kind="' + kind + '"]');
    var donut = card ? card.querySelector('.admin-seo-score-donut') : null;
    if (valEl) valEl.textContent = value > 0 ? String(value) : '—';
    if (card) {
      card.classList.remove('is-good', 'is-ok', 'is-bad');
      if (value > 0) card.classList.add(scoreClass(value));
    }
    if (donut) {
      donut.style.setProperty('--score-p', value > 0 ? (Math.min(100, value) / 100).toFixed(4) : '0');
    }
    if (statusEl) {
      if (value > 0) {
        statusEl.textContent = scoreStatusLabel(value);
        statusEl.hidden = false;
      } else {
        statusEl.textContent = '';
        statusEl.hidden = true;
      }
    }
  }

  function renderInternalLinks(links) {
    var list = el('seo-internal-links-list');
    var empty = el('seo-internal-links-empty');
    if (!list) return;
    list.innerHTML = '';
    if (!links || links.length === 0) {
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    links.forEach(function (l) {
      var li = document.createElement('li');
      li.className = 'admin-seo-check admin-seo-check--ok';
      li.innerHTML = '<span class="admin-seo-check-dot" aria-hidden="true"></span><span><a class="admin-link" href="' + escapeHtml(l.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(l.title) + '</a> <span class="admin-hint">(' + escapeHtml(l.kind) + ')</span></span>';
      list.appendChild(li);
    });
  }

  var analyzeTimer = null;

  function runAnalyze() {
    var root = el('admin-seo-analysis-root');
    if (!root) return;
    var fd = new FormData();
    fd.append('_csrf_token', csrfToken());
    fd.append('title', titleInput() ? titleInput().value : '');
    fd.append('slug', slugInput() ? slugInput().value : '');
    fd.append('seo_title', seoTitleInput() ? seoTitleInput().value : '');
    fd.append('seo_description', seoDescInput() ? seoDescInput().value : '');
    fd.append('focus_keyphrase', focusInput() ? focusInput().value : '');
    fd.append('content', contentInput() ? contentInput().value : '');
    fd.append('entity_kind', root.getAttribute('data-entity-kind') || 'page');
    fd.append('entity_id', root.getAttribute('data-entity-id') || '');

    fetch(root.getAttribute('data-analyze-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.analysis) return;
        setScore('seo', data.analysis.seo_score || 0);
        setScore('readability', data.analysis.readability_score || 0);
        renderChecks(el('seo-checklist-seo'), data.analysis.seo_checks);
        renderChecks(el('seo-checklist-readability'), data.analysis.readability_checks);
        renderInternalLinks(data.internal_links || []);
      })
      .catch(function () { /* silent */ });
  }

  function scheduleAnalyze() {
    updateSerpPreview();
    if (analyzeTimer) clearTimeout(analyzeTimer);
    analyzeTimer = setTimeout(runAnalyze, 400);
  }

  function initTabs() {
    document.querySelectorAll('.admin-seo-analysis-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var tab = btn.getAttribute('data-tab');
        document.querySelectorAll('.admin-seo-analysis-tab').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
          b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
        });
        document.querySelectorAll('[data-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-panel') !== tab;
        });
      });
    });
  }

  function bindInputs() {
    var ids = ['page-title', 'page-slug', 'page-seo-title', 'page-seo-desc', 'page-content', 'ent-title', 'ent-slug', 'ent-seo-title', 'ent-seo-desc', 'seo-focus-keyphrase', 'tm-name', 'tm-slug', 'seo-suite-title', 'seo-suite-desc'];
    ids.forEach(function (id) {
      var node = el(id);
      if (node) {
        node.addEventListener('input', scheduleAnalyze);
        node.addEventListener('change', scheduleAnalyze);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!el('admin-seo-analysis-root')) return;
    initTabs();
    bindInputs();
    scheduleAnalyze();
  });
})();
