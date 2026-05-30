(function () {
  'use strict';

  function readFieldValue(form, key) {
    var els = form.querySelectorAll('[name="' + key + '"], [name="' + key + '[]"]');
    if (!els.length) return '';
    var first = els[0];
    if (first.type === 'checkbox' && els.length === 1) {
      return first.checked ? '1' : '';
    }
    if (first.type === 'checkbox') {
      var vals = [];
      els.forEach(function (el) {
        if (el.checked) vals.push(el.value);
      });
      return vals.join(', ');
    }
    if (first.type === 'radio') {
      var checked = form.querySelector('[name="' + key + '"]:checked');
      return checked ? checked.value : '';
    }
    if (first.tagName === 'SELECT') {
      return first.value || '';
    }
    return first.value || '';
  }

  function ruleMatches(rule, form) {
    var actual = readFieldValue(form, rule.field_key || '');
    var expected = rule.value || '';
    var op = rule.operator || 'is';
    switch (op) {
      case 'is': return actual.toLowerCase() === expected.toLowerCase();
      case 'is_not': return actual.toLowerCase() !== expected.toLowerCase();
      case 'contains': return actual.toLowerCase().indexOf(expected.toLowerCase()) !== -1;
      case 'not_contains': return actual.toLowerCase().indexOf(expected.toLowerCase()) === -1;
      case 'empty': return actual.trim() === '';
      case 'not_empty': return actual.trim() !== '';
      default: return false;
    }
  }

  function applyConditionals(form) {
    form.querySelectorAll('[data-conditional]').forEach(function (wrap) {
      var raw = wrap.getAttribute('data-conditional');
      if (!raw) return;
      var rules;
      try { rules = JSON.parse(raw); } catch (e) { return; }
      var matched = (rules.rules || []).every(function (r) { return ruleMatches(r, form); });
      var show = (rules.action || 'show') === 'show' ? matched : !matched;
      wrap.hidden = !show;
      wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (!show) {
          el.removeAttribute('required');
          el.dataset.wasRequired = el.required ? '1' : '';
        } else if (el.dataset.wasRequired === '1') {
          el.setAttribute('required', 'required');
        }
      });
    });
  }

  function initMultipage(form) {
    var pages = form.querySelectorAll('[data-struxa-form-page]');
    if (pages.length < 2) return null;
    var prevBtn = form.querySelector('[data-struxa-form-prev]');
    var nextBtn = form.querySelector('[data-struxa-form-next]');
    var submitBtn = form.querySelector('[data-struxa-form-submit]');
    var dots = form.closest('[data-struxa-form-root]').querySelectorAll('[data-struxa-form-progress] [data-page]');
    var index = 0;

    function showPage(i) {
      index = i;
      pages.forEach(function (p, n) {
        p.hidden = n !== i;
        p.classList.toggle('is-active', n === i);
      });
      dots.forEach(function (d, n) {
        d.classList.toggle('is-active', n === i);
      });
      if (prevBtn) prevBtn.hidden = i === 0;
      if (nextBtn) nextBtn.hidden = i === pages.length - 1;
      if (submitBtn) submitBtn.hidden = i !== pages.length - 1;
    }

    function validatePage(i) {
      var page = pages[i];
      var invalid = null;
      page.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.closest('[hidden]')) return;
        if (!el.checkValidity()) invalid = el;
      });
      if (invalid) {
        invalid.reportValidity();
        return false;
      }
      return true;
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        if (!validatePage(index)) return;
        showPage(Math.min(index + 1, pages.length - 1));
      });
    }
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        showPage(Math.max(index - 1, 0));
      });
    }
    showPage(0);
    return { validatePage: validatePage, getIndex: function () { return index; } };
  }

  document.querySelectorAll('[data-struxa-form]').forEach(function (form) {
    var mp = initMultipage(form);
    var refresh = function () { applyConditionals(form); };
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    refresh();

    form.addEventListener('submit', function (e) {
      refresh();
      if (mp && !mp.validatePage(mp.getIndex())) {
        e.preventDefault();
      }
    });
  });
})();
