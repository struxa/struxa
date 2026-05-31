/**
 * YouTube / X (Twitter) oEmbed helpers for TinyMCE rich-text fields.
 */
(function (global) {
  'use strict';

  var OEMBED_PATH = global.CMS_RICHTEXT_OEMBED_URL || '/admin/richtext/oembed';

  function looksEmbeddable(url) {
    return /(?:youtube\.com|youtu\.be|twitter\.com|x\.com)/i.test(url);
  }

  function resolveOembed(url) {
    return fetch(OEMBED_PATH + '?url=' + encodeURIComponent(url), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data || !data.ok || !data.html) {
            throw new Error((data && data.error) || 'Unsupported URL');
          }
          return data.html;
        });
      });
  }

  function bindPasteToEmbed(editor) {
    editor.on('paste', function (e) {
      var clip = e.clipboardData;
      if (!clip) return;
      var text = (clip.getData('text/plain') || '').trim();
      if (!text || text.indexOf('\n') !== -1 || text.indexOf('\r') !== -1) return;
      if (!looksEmbeddable(text)) return;
      e.preventDefault();
      resolveOembed(text)
        .then(function (html) {
          editor.insertContent(html);
        })
        .catch(function () {
          editor.insertContent(
            '<a href="' + editor.dom.encode(text) + '">' + editor.dom.encode(text) + '</a>'
          );
        });
    });
  }

  function mergeSetup(existingSetup) {
    return function (editor) {
      if (typeof existingSetup === 'function') {
        existingSetup(editor);
      }
      bindPasteToEmbed(editor);
    };
  }

  function applyToConfig(config) {
    config.media_url_resolver = function (data, resolve, reject) {
      resolveOembed(data.url)
        .then(function (html) {
          resolve({ html: html });
        })
        .catch(function (err) {
          if (typeof reject === 'function') {
            reject(err && err.message ? err.message : 'Unsupported media URL.');
          }
        });
    };
    config.setup = mergeSetup(config.setup);
    return config;
  }

  global.cmsRichTextOembed = {
    looksEmbeddable: looksEmbeddable,
    resolve: resolveOembed,
    bindEditor: bindPasteToEmbed,
    mergeSetup: mergeSetup,
    applyToConfig: applyToConfig,
  };
})(window);
