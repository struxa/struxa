/**
 * TinyMCE for one or more rich-text fields (content entry custom fields).
 * Each block: [data-admin-rich-text] with data-textarea-id and data-autosave-prefix.
 */
(function () {
  'use strict';

  var entryFormBaselineCleared = false;
  var bootAttempts = 0;
  var MAX_BOOT_ATTEMPTS = 24;

  function whenTinyMceReady(cb) {
    if (typeof tinymce !== 'undefined') {
      cb();
      return;
    }
    var script = document.getElementById('cms-tinymce-cdn');
    var done = false;
    function finish() {
      if (done || typeof tinymce === 'undefined') return;
      done = true;
      cb();
    }
    if (script) {
      script.addEventListener('load', finish, { once: true });
    }
    var tries = 0;
    var poll = window.setInterval(function () {
      tries += 1;
      if (typeof tinymce !== 'undefined') {
        window.clearInterval(poll);
        finish();
      } else if (tries >= 120) {
        window.clearInterval(poll);
      }
    }, 50);
  }

  function afterLayout(cb) {
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(function () {
        requestAnimationFrame(cb);
      });
      return;
    }
    cb();
  }

  function textareaReady(ta) {
    if (!ta || !ta.isConnected) return false;
    var details = ta.closest('details');
    if (details && !details.open) return false;
    var rect = ta.getBoundingClientRect();
    return rect.width > 0 || rect.height > 0;
  }

  function getConfig(textareaId, autosavePrefix) {
    return {
      license_key: 'gpl',
      base_url: 'https://cdn.jsdelivr.net/npm/tinymce@7.9.2',
      suffix: '.min',
      selector: '#' + textareaId,
      promotion: false,
      branding: false,
      skin: 'oxide-dark',
      content_css: ['/css/tinymce-content.css'],
      content_style:
        'html{background:#12161f!important;}body.mce-content-body,body{background:#12161f!important;color:#e4e4e7!important;}',
      min_height: 520,
      max_height: 960,
      menubar: 'edit insert view format tools table help',
      statusbar: true,
      resize: true,
      browser_spellcheck: true,
      link_context_toolbar: true,
      image_advtab: true,
      image_caption: false,
      object_resizing: true,
      resize_img_proportional: true,
      table_toolbar:
        'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
      table_appearance_options: true,
      table_grid: true,
      table_advtab: true,
      table_cell_advtab: true,
      table_row_advtab: true,
      table_resize_bars: true,
      table_default_attributes: { border: '1' },
      table_default_styles: { 'border-collapse': 'collapse', width: '100%' },
      media_live_embeds: true,
      media_alt_source: false,
      paste_data_images: false,
      relative_urls: false,
      remove_script_host: false,
      convert_urls: true,
      link_default_target: '_blank',
      link_default_protocol: 'https',
      importcss_append: true,
      importcss_selector_filter: /\.cms-/,
      quickbars_selection_toolbar:
        'bold italic underline | styles | quicklink | alignleft aligncenter alignright | bullist numlist | blockquote removeformat',
      quickbars_insert_toolbar: 'image media quicktable | hr accordion | codesample charmap',
      contextmenu: 'link image table',
      block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Heading 5=h5; Heading 6=h6; Preformatted=pre',
      style_formats_merge: true,
      style_formats: [
        {
          title: 'Paragraph styles',
          items: [
            { title: 'Lead / intro', selector: 'p', classes: 'cms-intro' },
            { title: 'Callout box', block: 'div', classes: 'cms-callout', wrapper: true },
            { title: 'Caption line', inline: 'span', classes: 'cms-caption' },
          ],
        },
      ],
      plugins: [
        'accordion',
        'advlist',
        'anchor',
        'autolink',
        'autoresize',
        'autosave',
        'charmap',
        'code',
        'codesample',
        'directionality',
        'emoticons',
        'fullscreen',
        'help',
        'image',
        'importcss',
        'insertdatetime',
        'link',
        'lists',
        'media',
        'nonbreaking',
        'pagebreak',
        'preview',
        'quickbars',
        'searchreplace',
        'table',
        'visualblocks',
        'visualchars',
        'wordcount',
      ].join(' '),
      toolbar: [
        'undo redo | blocks | styles | bold italic underline strikethrough subscript superscript | forecolor backcolor | alignleft aligncenter alignright alignjustify',
        'bullist numlist outdent indent | link unlink anchor | image media | accordion | table tableprops tablemergecells | codesample | charmap emoticons nonbreaking hr pagebreak insertdatetime',
        'searchreplace | visualblocks visualchars | ltr rtl | preview | code fullscreen | removeformat | help',
      ].join(' | '),
      toolbar_mode: 'sliding',
      toolbar_sticky: true,
      autosave_ask_before_unload: false,
      autosave_interval: '25s',
      autosave_retention: '2d',
      autosave_prefix: autosavePrefix,
      codesample_languages: [
        { text: 'HTML/XML', value: 'markup' },
        { text: 'JavaScript', value: 'javascript' },
        { text: 'CSS', value: 'css' },
        { text: 'PHP', value: 'php' },
        { text: 'JSON', value: 'json' },
        { text: 'SQL', value: 'sql' },
        { text: 'Bash', value: 'bash' },
        { text: 'Python', value: 'python' },
      ],
      emoticons_database: 'emojis',
      pagebreak_separator: '<!-- pagebreak -->',
      init_instance_callback: function (editor) {
        var el = editor.getElement && editor.getElement();
        var root = el && el.closest ? el.closest('[data-admin-rich-text]') : null;
        if (root) root.setAttribute('data-rich-text-bound', '1');
        if (entryFormBaselineCleared) return;
        entryFormBaselineCleared = true;
        requestAnimationFrame(function () {
          if (window.adminFormUx && typeof window.adminFormUx.clearDirtyForForm === 'function') {
            var f = document.getElementById('entry-edit-form');
            if (f) window.adminFormUx.clearDirtyForForm(f);
          }
        });
      },
      setup: function (editor) {
        editor.on('change input undo redo', function () {
          editor.save();
        });
      },
    };
  }

  function bindRichTextBlock(root) {
    var taId = root.getAttribute('data-textarea-id');
    var autosavePrefix = root.getAttribute('data-autosave-prefix') || 'cms-entry-field-';
    if (!taId || typeof tinymce === 'undefined') return;

    var ta = document.getElementById(taId);
    if (!ta || !textareaReady(ta)) return;

    if (tinymce.get(taId)) {
      root.setAttribute('data-rich-text-bound', '1');
      return;
    }

    var surface = root.querySelector('.admin-page-editor-surface');
    var btnVisual = root.querySelector('[data-editor-mode="visual"]');
    var btnHtml = root.querySelector('[data-editor-mode="html"]');
    var hintVisual = root.querySelector('.admin-editor-hint-visual');
    var hintHtml = root.querySelector('.admin-editor-hint-html');
    if (!surface || !btnVisual || !btnHtml) return;

    if (root.getAttribute('data-rich-text-ui-bound') !== '1') {
      root.setAttribute('data-rich-text-ui-bound', '1');

      function showHints(isHtml) {
        if (hintVisual) {
          hintVisual.style.display = isHtml ? 'none' : '';
          if (isHtml) hintVisual.setAttribute('hidden', '');
          else hintVisual.removeAttribute('hidden');
        }
        if (hintHtml) {
          hintHtml.style.display = isHtml ? '' : 'none';
          if (isHtml) hintHtml.removeAttribute('hidden');
          else hintHtml.setAttribute('hidden', '');
        }
      }

      function setMode(mode) {
        var isHtml = mode === 'html';
        if (isHtml) {
          var ed = tinymce.get(taId);
          if (ed) {
            ta.value = ed.getContent();
            ed.remove();
          }
          ta.style.display = 'block';
          ta.classList.add('admin-textarea--html-mode');
          surface.classList.add('is-html-mode');
          btnVisual.classList.remove('is-active');
          btnHtml.classList.add('is-active');
          btnVisual.setAttribute('aria-selected', 'false');
          btnHtml.setAttribute('aria-selected', 'true');
          showHints(true);
          ta.focus();
        } else {
          surface.classList.remove('is-html-mode');
          ta.classList.remove('admin-textarea--html-mode');
          ta.style.display = '';
          btnHtml.classList.remove('is-active');
          btnVisual.classList.add('is-active');
          btnVisual.setAttribute('aria-selected', 'true');
          btnHtml.setAttribute('aria-selected', 'false');
          showHints(false);
          if (!tinymce.get(taId)) {
            tinymce.init(getConfig(taId, autosavePrefix));
          }
        }
      }

      btnVisual.addEventListener('click', function () {
        setMode('visual');
      });
      btnHtml.addEventListener('click', function () {
        setMode('html');
      });

      showHints(false);
    }

    if (!tinymce.get(taId)) {
      tinymce.init(getConfig(taId, autosavePrefix));
    }
  }

  function bootRichTextBlocks() {
    if (typeof tinymce === 'undefined') return false;

    var roots = document.querySelectorAll('[data-admin-rich-text]');
    if (!roots.length) return true;

    var pending = 0;
    roots.forEach(function (root) {
      var taId = root.getAttribute('data-textarea-id');
      var ta = taId ? document.getElementById(taId) : null;
      if (taId && tinymce.get(taId)) {
        root.setAttribute('data-rich-text-bound', '1');
        return;
      }
      if (ta && !textareaReady(ta)) {
        pending += 1;
        return;
      }
      bindRichTextBlock(root);
      if (taId && !tinymce.get(taId)) pending += 1;
    });

    return pending === 0;
  }

  function scheduleBoot(force) {
    whenTinyMceReady(function () {
      afterLayout(function () {
        var complete = bootRichTextBlocks();
        if (!complete && bootAttempts < MAX_BOOT_ATTEMPTS) {
          bootAttempts += 1;
          window.setTimeout(scheduleBoot, 120);
        }
      });
    });
  }

  function init() {
    scheduleBoot(false);

    window.addEventListener('load', function () {
      bootAttempts = 0;
      scheduleBoot(true);
    }, { once: true });

    var form = document.getElementById('entry-edit-form') || document.querySelector('form.admin-form');
    if (form && !form.getAttribute('data-rich-text-submit-bound')) {
      form.setAttribute('data-rich-text-submit-bound', '1');
      form.addEventListener('submit', function () {
        if (typeof tinymce !== 'undefined') tinymce.triggerSave();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
