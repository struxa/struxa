/**
 * Unsaved-change guard, banner, TinyMCE → dirty, Cmd/Ctrl+S → primary save.
 */
(function () {
  'use strict';

  var formStateByForm = new WeakMap();
  var allStates = [];

  function markDirty(state) {
    state.dirty = true;
    var banner = document.getElementById('admin-unsaved-banner');
    if (banner) banner.hidden = false;
  }

  function clearDirty(state) {
    state.dirty = false;
    var banner = document.getElementById('admin-unsaved-banner');
    if (banner && !allStates.some(function (s) { return s.dirty; })) banner.hidden = true;
  }

  function hookEditor(ed) {
    if (ed._cmsUxDirty) return;
    var el = ed.getElement && ed.getElement();
    if (!el || !el.closest) return;
    var form = el.closest('form');
    if (!form) return;
    var state = formStateByForm.get(form);
    if (!state) return;
    ed._cmsUxDirty = true;
    /* Omit SetContent: TinyMCE fires it on init/sync; that is not a user edit and falsely
       triggers “unsaved changes” (e.g. empty body + visual builder only). */
    ed.on('Change Undo Redo', function () {
      markDirty(state);
    });
  }

  function bindTinyMceAll() {
    if (typeof tinymce === 'undefined') return;
    if (!bindTinyMceAll._listener) {
      bindTinyMceAll._listener = true;
      tinymce.on('AddEditor', function (e) {
        hookEditor(e.editor);
      });
    }
    if (tinymce.editors) tinymce.editors.forEach(hookEditor);
  }

  function scheduleTinyMceBind() {
    [0, 400, 1200].forEach(function (ms) {
      setTimeout(bindTinyMceAll, ms);
    });
  }

  var uxGlobalBound = false;

  function bindGlobalOnce() {
    if (uxGlobalBound) return;
    uxGlobalBound = true;

    window.addEventListener('beforeunload', function (e) {
      if (!allStates.some(function (s) { return s.dirty; })) return;
      e.preventDefault();
      e.returnValue = '';
    });

    document.addEventListener('keydown', function (e) {
      if (e.defaultPrevented || !(e.metaKey || e.ctrlKey) || String(e.key).toLowerCase() !== 's') return;
      var ae = document.activeElement;
      var form =
        (ae && ae.closest && ae.closest('form#entry-edit-form, form#page-edit-form, form#section-edit-form')) ||
        document.querySelector('form#entry-edit-form, form#page-edit-form, form#section-edit-form');
      if (!form) return;
      e.preventDefault();
      var primary = form.querySelector('[data-admin-save-primary]');
      if (primary && !primary.disabled) primary.click();
    });
  }

  function initForm(form) {
    if (!form || form.getAttribute('data-admin-form-ux') === '1') return;
    form.setAttribute('data-admin-form-ux', '1');
    bindGlobalOnce();

    var state = { dirty: false };
    formStateByForm.set(form, state);
    allStates.push(state);

    form.addEventListener(
      'input',
      function () {
        markDirty(state);
      },
      true
    );
    form.addEventListener(
      'change',
      function () {
        markDirty(state);
      },
      true
    );

    form.addEventListener('submit', function () {
      clearDirty(state);
    });

    scheduleTinyMceBind();
  }

  function run() {
    document.querySelectorAll('form#entry-edit-form, form#page-edit-form, form#section-edit-form').forEach(initForm);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();

  /** Call after TinyMCE (or similar) finishes init so baseline matches post-load DOM. */
  function clearDirtyForForm(form) {
    if (!form) return;
    var state = formStateByForm.get(form);
    if (state) clearDirty(state);
  }

  window.adminFormUx = window.adminFormUx || {};
  window.adminFormUx.clearDirtyForForm = clearDirtyForForm;
})();
