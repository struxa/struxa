(function () {
  var LS_KEY = 'struxa_admin_ai_chat_open';
  var root = document.getElementById('admin-ai-chat');
  if (!root) return;

  var csrf = root.getAttribute('data-csrf') || '';
  var bootstrapUrl = root.getAttribute('data-bootstrap-url') || '';
  var launcher = document.getElementById('admin-ai-chat-launcher');
  var panel = document.getElementById('admin-ai-chat-panel');
  var minimizeBtn = document.getElementById('admin-ai-chat-minimize');
  var clearBtn = document.getElementById('admin-ai-chat-clear');
  var settingsLink = document.getElementById('admin-ai-chat-settings-link');
  var banner = document.getElementById('admin-ai-chat-banner');
  var messagesEl = document.getElementById('admin-ai-chat-messages');
  var form = document.getElementById('admin-ai-chat-form');
  var input = document.getElementById('admin-ai-chat-input');
  var sendBtn = document.getElementById('admin-ai-chat-send');
  var draftBox = document.getElementById('admin-ai-chat-draft');
  var typeSelect = document.getElementById('admin-ai-chat-type');
  var toneField = document.getElementById('admin-ai-chat-tone');
  var draftBtn = document.getElementById('admin-ai-chat-draft-btn');
  var draftHintEl = document.getElementById('admin-ai-chat-draft-hint');
  var inlineErrorEl = document.getElementById('admin-ai-chat-inline-error');

  var state = {
    loaded: false,
    urls: {},
    canChat: false,
    canCreateDraft: false,
    canSaveSettings: false,
    openaiReady: false,
  };

  /** Last server-backed messages (for reverting UI on error). */
  var messageSnapshot = [];

  /** Increment to cancel in-flight typewriter when a new send starts. */
  var typewriterGeneration = 0;

  /** Latest chat POST id — ignore stale responses if user sent again. */
  var chatRequestId = 0;

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function scrollMessagesToBottom() {
    if (messagesEl) messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function showInlineError(msg) {
    if (!inlineErrorEl) {
      if (msg) window.alert(msg);
      return;
    }
    inlineErrorEl.textContent = msg || '';
    inlineErrorEl.hidden = !msg;
  }

  function clearInlineError() {
    showInlineError('');
  }

  function renderEmptyHint() {
    if (!messagesEl) return;
    var empty = document.createElement('p');
    empty.className = 'admin-ai-chat-empty';
    empty.textContent =
      'Describe what you want to publish. I can help you refine the idea; then use Create draft entry to add a CMS draft.';
    messagesEl.appendChild(empty);
  }

  function appendMessageRow(role, contentText, bodyEl) {
    if (!messagesEl || (role !== 'user' && role !== 'assistant')) return;
    var row = document.createElement('div');
    row.className = 'admin-ai-chat-msg admin-ai-chat-msg--' + role;
    var lab = document.createElement('span');
    lab.className = 'admin-ai-chat-msg-label';
    lab.textContent = role === 'user' ? 'You' : 'Assistant';
    var body = bodyEl || document.createElement('div');
    if (!body.classList.contains('admin-ai-chat-msg-body')) {
      body.classList.add('admin-ai-chat-msg-body');
    }
    if (!bodyEl) {
      body.textContent = contentText || '';
    }
    row.appendChild(lab);
    row.appendChild(body);
    messagesEl.appendChild(row);
    return row;
  }

  function appendThinkingRow() {
    if (!messagesEl) return null;
    var row = document.createElement('div');
    row.className = 'admin-ai-chat-msg admin-ai-chat-msg--assistant admin-ai-chat-msg--thinking';
    row.setAttribute('aria-live', 'polite');
    row.setAttribute('aria-busy', 'true');
    var lab = document.createElement('span');
    lab.className = 'admin-ai-chat-msg-label';
    lab.textContent = 'Assistant';
    var body = document.createElement('div');
    body.className = 'admin-ai-chat-msg-body admin-ai-chat-msg-body--thinking';
    body.innerHTML =
      '<span class="admin-ai-chat-thinking-label">Thinking</span><span class="admin-ai-chat-thinking-dots" aria-hidden="true"><span class="admin-ai-chat-dot">.</span><span class="admin-ai-chat-dot">.</span><span class="admin-ai-chat-dot">.</span></span>';
    row.appendChild(lab);
    row.appendChild(body);
    messagesEl.appendChild(row);
    scrollMessagesToBottom();
    return row;
  }

  function removeThinkingRows() {
    if (!messagesEl) return;
    messagesEl.querySelectorAll('.admin-ai-chat-msg--thinking').forEach(function (n) {
      n.remove();
    });
  }

  /**
   * Show prior messages + user line + thinking indicator (while OpenAI runs).
   */
  function renderPendingSend(prior, userText) {
    if (!messagesEl) return;
    messagesEl.innerHTML = '';
    if (prior && prior.length) {
      prior.forEach(function (m) {
        if (!m || (m.role !== 'user' && m.role !== 'assistant')) return;
        appendMessageRow(m.role, m.content || '');
      });
    } else if (!userText) {
      renderEmptyHint();
      return;
    }
    appendMessageRow('user', userText);
    appendThinkingRow();
    scrollMessagesToBottom();
  }

  function runTypewriter(bodyEl, fullText, gen) {
    if (!bodyEl || fullText === '') return;
    if (prefersReducedMotion() || fullText.length > 12000) {
      bodyEl.textContent = fullText;
      scrollMessagesToBottom();
      return;
    }
    bodyEl.textContent = '';
    var i = 0;
    var len = fullText.length;

    function tick() {
      if (gen !== typewriterGeneration) return;
      if (i >= len) return;
      var chunk = 1;
      if (len > 400) chunk = 2;
      if (len > 1200) chunk = 3;
      var next = Math.min(len, i + chunk);
      bodyEl.textContent = fullText.slice(0, next);
      i = next;
      scrollMessagesToBottom();
      if (i >= len) return;
      var ch = fullText.charAt(i - 1);
      var pause = ch === '\n' ? 28 : /[.!?]\s/.test(fullText.slice(Math.max(0, i - 2), i + 1)) ? 22 : 14;
      setTimeout(tick, pause);
    }
    tick();
  }

  function lastAssistantIndex(msgs) {
    var idx = -1;
    if (!msgs) return -1;
    for (var i = 0; i < msgs.length; i++) {
      if (msgs[i] && msgs[i].role === 'assistant') idx = i;
    }
    return idx;
  }

  function setOpen(isOpen) {
    root.classList.toggle('admin-ai-chat--open', isOpen);
    if (panel) {
      panel.hidden = !isOpen;
      panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }
    if (launcher) {
      launcher.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      launcher.hidden = isOpen;
      launcher.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
    }
    if (!isOpen && panel && document.activeElement && panel.contains(document.activeElement)) {
      if (typeof document.activeElement.blur === 'function') {
        document.activeElement.blur();
      }
    }
    try {
      localStorage.setItem(LS_KEY, isOpen ? '1' : '0');
    } catch (e) {}
    if (isOpen) {
      if (messagesEl && typeof messagesEl.focus === 'function') {
        messagesEl.focus({ preventScroll: true });
      }
      loadBootstrap();
    }
  }

  /**
   * @param {object} [opts]
   * @param {boolean} [opts.animateLastAssistant] type out the latest assistant reply
   */
  function renderMessages(msgs, opts) {
    opts = opts || {};
    messageSnapshot = msgs && msgs.length ? msgs.slice() : [];
    if (!messagesEl) return;
    messagesEl.innerHTML = '';
    if (!msgs || !msgs.length) {
      renderEmptyHint();
      scrollMessagesToBottom();
      return;
    }

    var lastAi = opts.animateLastAssistant === true ? lastAssistantIndex(msgs) : -1;

    msgs.forEach(function (m, idx) {
      if (!m || (m.role !== 'user' && m.role !== 'assistant')) return;
      var body = document.createElement('div');
      body.className = 'admin-ai-chat-msg-body';
      if (m.role === 'assistant' && idx === lastAi && !prefersReducedMotion() && (m.content || '').length <= 12000) {
        appendMessageRow(m.role, '', body);
        typewriterGeneration += 1;
        var gen = typewriterGeneration;
        runTypewriter(body, m.content || '', gen);
      } else {
        body.textContent = m.content || '';
        appendMessageRow(m.role, '', body);
      }
    });
    scrollMessagesToBottom();
  }

  function applyBootstrap(data) {
    state.loaded = true;
    state.urls = data.urls || {};
    state.canChat = !!data.can_chat;
    state.canCreateDraft = !!data.can_create_draft;
    state.canSaveSettings = !!data.can_save_settings;
    state.openaiReady = !!data.openai_ready;
    clearInlineError();
    renderMessages(data.messages || []);

    if (settingsLink) settingsLink.hidden = !state.canSaveSettings;

    if (banner) {
      if (!state.openaiReady) {
        banner.hidden = false;
        banner.textContent =
          'OpenAI is off or no API key. ' +
          (state.canSaveSettings
            ? 'Use System → API keys for the key and AI draft to enable the model.'
            : 'Ask an administrator to add the key (System → API keys) and enable AI draft.');
      } else {
        banner.hidden = false;
        var rl = data.rate_limits || {};
        var us = data.usage || {};
        var bits = ['AI assistant ready.'];
        if (rl.chat_per_hour != null && rl.draft_per_day != null) {
          bits.push('Limits: ' + rl.chat_per_hour + ' chat/hr, ' + rl.draft_per_day + ' drafts/24h per user.');
        }
        if (us.chat_24h != null) {
          bits.push('Your last 24h: ' + us.chat_24h + ' chat, ' + (us.draft_24h || 0) + ' drafts.');
        }
        if (data.ai_chat_persist) {
          bits.push('History is persisted in the database.');
        }
        banner.textContent = bits.join(' ');
      }
    }

    if (form) form.hidden = !state.canChat;
    if (clearBtn) clearBtn.hidden = !state.canChat;
    if (draftBox) draftBox.hidden = !state.canCreateDraft;
    if (draftHintEl) draftHintEl.hidden = !state.canCreateDraft;

    if (typeSelect) {
      typeSelect.innerHTML = '';
      (data.content_types || []).forEach(function (t) {
        var o = document.createElement('option');
        o.value = String(t.id);
        o.textContent = t.name + ' (' + t.slug + ')';
        typeSelect.appendChild(o);
      });
    }
  }

  function loadBootstrap() {
    if (state.loaded) return;
    fetch(bootstrapUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        applyBootstrap(data);
      })
      .catch(function () {
        if (banner) {
          banner.hidden = false;
          banner.textContent = 'Could not load assistant. Refresh the page.';
        }
      });
  }

  function postForm(url, fields) {
    var fd = new FormData();
    fd.append('_csrf_token', csrf);
    Object.keys(fields).forEach(function (k) {
      fd.append(k, fields[k]);
    });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd, headers: { Accept: 'application/json' } }).then(function (r) {
      return r.text().then(function (t) {
        var j = {};
        try {
          j = t ? JSON.parse(t) : {};
        } catch (e) {
          j = { error: 'Invalid server response.' };
        }
        return { ok: r.ok, status: r.status, json: j };
      });
    });
  }

  root.removeAttribute('hidden');

  if (launcher) {
    launcher.addEventListener('click', function () {
      setOpen(true);
    });
  }
  if (minimizeBtn) {
    minimizeBtn.addEventListener('click', function () {
      setOpen(false);
    });
  }

  try {
    if (localStorage.getItem(LS_KEY) === '1') {
      setOpen(true);
    }
  } catch (e) {}

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!state.canChat || !state.urls.chat) return;
      var text = (input && input.value) || '';
      text = text.trim();
      if (!text) return;
      typewriterGeneration += 1;
      sendBtn.disabled = true;
      clearInlineError();
      var prior = messageSnapshot.slice();
      var myReq = ++chatRequestId;
      renderPendingSend(prior, text);
      if (input) input.value = '';

      var canStream =
        typeof fetch !== 'undefined' &&
        typeof ReadableStream !== 'undefined' &&
        typeof TextDecoder !== 'undefined';

      function finishChatError(msg) {
        if (myReq !== chatRequestId) return;
        removeThinkingRows();
        renderMessages(prior);
        showInlineError(msg);
        sendBtn.disabled = false;
      }

      if (canStream) {
        var fd = new FormData();
        fd.append('_csrf_token', csrf);
        fd.append('message', text);
        fd.append('stream', '1');
        fetch(state.urls.chat, {
          method: 'POST',
          credentials: 'same-origin',
          body: fd,
          headers: { Accept: 'text/event-stream' },
        })
          .then(function (res) {
            if (myReq !== chatRequestId) return;
            var ct = (res.headers.get('Content-Type') || '').toLowerCase();
            if (ct.indexOf('application/json') !== -1) {
              return res.text().then(function (t) {
                var j = {};
                try {
                  j = t ? JSON.parse(t) : {};
                } catch (e) {}
                finishChatError((j && j.error) || 'Request failed.');
              });
            }
            if (!res.ok || !res.body) {
              finishChatError('Request failed (' + res.status + ').');
              return;
            }
            var reader = res.body.getReader();
            var dec = new TextDecoder();
            var buf = '';
            var streamDone = false;

            function ensureAssistantBody() {
              removeThinkingRows();
              var body = document.createElement('div');
              body.className = 'admin-ai-chat-msg-body';
              appendMessageRow('assistant', '', body);
              scrollMessagesToBottom();
              return body;
            }
            var assistantBody = null;

            function pump() {
              return reader.read().then(function (result) {
                if (myReq !== chatRequestId) {
                  try {
                    reader.cancel();
                  } catch (e) {}
                  return;
                }
                if (result.done) {
                  if (!streamDone) {
                    finishChatError('Connection closed before the reply finished.');
                  }
                  return;
                }
                buf += dec.decode(result.value, { stream: true });
                var parts = buf.split('\n\n');
                buf = parts.pop() || '';
                for (var pi = 0; pi < parts.length; pi++) {
                  var lines = parts[pi].split('\n');
                  for (var li = 0; li < lines.length; li++) {
                    var line = lines[li];
                    if (line.indexOf('data: ') !== 0) continue;
                    var payload = line.slice(6).trim();
                    if (!payload) continue;
                    var ev;
                    try {
                      ev = JSON.parse(payload);
                    } catch (err) {
                      continue;
                    }
                    if (ev.type === 'delta' && ev.d) {
                      if (!assistantBody) assistantBody = ensureAssistantBody();
                      assistantBody.textContent += ev.d;
                      scrollMessagesToBottom();
                    } else if (ev.type === 'done') {
                      if (myReq !== chatRequestId) {
                        try {
                          reader.cancel();
                        } catch (c0) {}
                        return;
                      }
                      streamDone = true;
                      removeThinkingRows();
                      renderMessages(ev.messages || [], { animateLastAssistant: false });
                      sendBtn.disabled = false;
                      try {
                        reader.cancel();
                      } catch (c) {}
                      return;
                    } else if (ev.type === 'error') {
                      streamDone = true;
                      finishChatError(ev.message || 'Something went wrong.');
                      try {
                        reader.cancel();
                      } catch (c2) {}
                      return;
                    }
                  }
                }
                return pump();
              });
            }
            return pump().catch(function () {
              finishChatError('Network error while streaming.');
            });
          })
          .catch(function () {
            finishChatError('Network error.');
          });
        return;
      }

      postForm(state.urls.chat, { message: text })
        .then(function (res) {
          if (myReq !== chatRequestId) return;
          removeThinkingRows();
          if (res.json && res.json.ok) {
            renderMessages(res.json.messages || [], { animateLastAssistant: true });
          } else if (res.json && res.json.error) {
            renderMessages(prior);
            showInlineError(res.json.error);
          } else {
            renderMessages(prior);
            showInlineError('Request failed.');
          }
        })
        .catch(function () {
          if (myReq !== chatRequestId) return;
          removeThinkingRows();
          renderMessages(prior);
          showInlineError('Network error.');
        })
        .finally(function () {
          if (myReq === chatRequestId) sendBtn.disabled = false;
        });
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (!state.urls.clear) return;
      postForm(state.urls.clear, {})
        .then(function (res) {
          if (res.json && res.json.ok) {
            renderMessages([]);
          }
        })
        .catch(function () {});
    });
  }

  if (draftBtn) {
    draftBtn.addEventListener('click', function () {
      if (!state.canCreateDraft || !state.urls.create_draft) return;
      var tid = typeSelect && typeSelect.value ? typeSelect.value : '';
      if (!tid) {
        showInlineError('Choose a content type.');
        return;
      }
      clearInlineError();
      draftBtn.disabled = true;
      draftBtn.setAttribute('aria-busy', 'true');
      var prevLabel = draftBtn.textContent;
      draftBtn.textContent = 'Creating draft…';
      postForm(state.urls.create_draft, {
        content_type_id: tid,
        tone: (toneField && toneField.value) || '',
        from_chat: '1',
      })
        .then(function (res) {
          if (res.json && res.json.ok && res.json.redirect) {
            window.location.href = res.json.redirect;
            return;
          }
          if (res.json && res.json.error) {
            showInlineError(res.json.error);
          } else {
            showInlineError('Could not create draft.');
          }
        })
        .catch(function () {
          showInlineError('Network error.');
        })
        .finally(function () {
          draftBtn.disabled = false;
          draftBtn.removeAttribute('aria-busy');
          draftBtn.textContent = prevLabel;
        });
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && root.classList.contains('admin-ai-chat--open')) {
      setOpen(false);
    }
  });
})();
