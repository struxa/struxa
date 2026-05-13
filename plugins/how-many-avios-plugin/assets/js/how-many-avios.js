/**
 * How Many Avios — frontend widget behaviour.
 *
 * Wires up the calculator form (typeahead destinations, season / currency
 * pills, search submit, optional prefill from deep-links elsewhere on the
 * page) against the plugin's public API (/api/how-many-avios/destinations
 * and /api/how-many-avios/price).
 *
 * Loaded via the plugin asset route with `defer`, so it runs once the DOM
 * has been parsed. The module-level dedupe guard makes it safe to include
 * the widget twice on the same page — only the first inclusion bootstraps
 * the listeners.
 */
(function () {
  'use strict';

  // Module-level dedupe — if the script is loaded twice (eg. the widget is
  // included on a page that also includes a deep-linked second instance),
  // we still only want one click delegate, one form listener, one fetch.
  if (window.__hmaWidgetInit) return;
  window.__hmaWidgetInit = true;

  const $ = (id) => document.getElementById(id);
  const form = $('hma-form');
  if (!form) return;

  const toInput      = $('hma-to');
  const cabinSel     = $('hma-cabin-sel');
  const seasonInput  = $('hma-season');
  const currencyInput = $('hma-currency');
  const datalist     = $('hma-destinations');
  const result       = $('hma-result');
  const resultRoute  = $('hma-result-route');
  const resultSub    = $('hma-result-sub');
  const resultAmt    = $('hma-result-amount');
  const errorEl      = $('hma-error');

  let destIndex = []; // { iata, label, name }

  // ------------------------------------------------------------------
  // 1) Load destinations once and populate the datalist (typeahead).
  // ------------------------------------------------------------------
  fetch('/api/how-many-avios/destinations')
    .then((r) => r.json())
    .then((data) => {
      destIndex = data.destinations || [];
      datalist.innerHTML = destIndex
        .map((d) => '<option value="' + d.label.replace(/"/g, '&quot;') + '">')
        .join('');
    })
    .catch(() => {});

  // ------------------------------------------------------------------
  // 1b) Pre-fill on click from anywhere on the page that opts in via:
  //     data-hma-prefill="1"
  //     data-hma-iata="..."
  //     data-hma-cabin="..."
  //     data-hma-season="..."
  //
  // The hero "Today's Top Deal" card uses this to deep-link into the
  // calculator without duplicating its state.
  // ------------------------------------------------------------------
  function setPill(pill, datasetKey, value) {
    pill.querySelectorAll('.hma-pill__opt').forEach((b) => {
      const match = b.dataset[datasetKey] === value;
      b.classList.toggle('is-active', match);
      b.setAttribute('aria-selected', match ? 'true' : 'false');
    });
  }

  function prefillFrom(el) {
    const iata = (el.dataset.hmaIata || '').toUpperCase();
    if (!iata) return;
    const destLabel = (el.dataset.hmaDestination || '') + (iata ? ' (' + iata + ')' : '');
    toInput.value = destLabel.trim() || iata;

    const cabin = el.dataset.hmaCabin;
    if (cabin) {
      const opt = Array.from(cabinSel.options).find((o) => o.value === cabin);
      if (opt) cabinSel.value = cabin;
    }

    const season = el.dataset.hmaSeason;
    if (season) {
      seasonInput.value = season;
      document.querySelectorAll('.hma-card .hma-pill').forEach((p) => {
        if (p.querySelector('[data-season]')) setPill(p, 'season', season);
      });
    }
  }

  document.addEventListener('click', (ev) => {
    const link = ev.target.closest('[data-hma-prefill]');
    if (!link) return;
    const href = (link.getAttribute('href') || '').trim();
    // Real path (e.g. /destinations/…) — let the browser navigate; do not prefill/submit.
    if (href !== '' && href !== '#' && !href.startsWith('#')) {
      return;
    }
    prefillFrom(link);
    // Tiny delay so the browser handles the hash jump first, then we
    // submit and render the result.
    setTimeout(() => {
      try {
        form.requestSubmit();
      } catch (_) {
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    }, 30);
  });

  // ------------------------------------------------------------------
  // 2) Wire the pill toggles (Peak/Off-peak and Avios/GBP).
  // ------------------------------------------------------------------
  document.querySelectorAll('.hma-card .hma-pill').forEach((pill) => {
    pill.querySelectorAll('.hma-pill__opt').forEach((btn) => {
      btn.addEventListener('click', () => {
        pill.querySelectorAll('.hma-pill__opt').forEach((b) => {
          b.classList.remove('is-active');
          b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
        if (btn.dataset.season)   seasonInput.value   = btn.dataset.season;
        if (btn.dataset.currency) currencyInput.value = btn.dataset.currency;
        // Re-fire the search if a result is already displayed so the
        // user sees the toggle take effect immediately.
        if (!result.hidden) form.requestSubmit();
      });
    });
  });

  // ------------------------------------------------------------------
  // 3) Resolve the user-typed string to an IATA code.
  // ------------------------------------------------------------------
  function resolveIata(text) {
    const t = (text || '').trim();
    if (!t) return null;
    const upper = t.toUpperCase();

    // Exact 3-letter IATA the user typed?
    if (/^[A-Z]{3}$/.test(upper)) {
      if (destIndex.some((d) => d.iata === upper)) return upper;
    }

    // Match against the labels we populated the datalist with.
    let hit = destIndex.find((d) => d.label.toLowerCase() === t.toLowerCase());
    if (hit) return hit.iata;

    // Trailing "(JFK)" pattern.
    const m = t.match(/\(([A-Za-z]{3})\)\s*$/);
    if (m) {
      const code = m[1].toUpperCase();
      if (destIndex.some((d) => d.iata === code)) return code;
    }

    // Loose name match.
    hit = destIndex.find((d) => d.name.toLowerCase() === t.toLowerCase());
    return hit ? hit.iata : null;
  }

  function showError(msg) {
    result.hidden = true;
    errorEl.textContent = msg;
    errorEl.hidden = false;
  }

  function clearError() {
    errorEl.hidden = true;
    errorEl.textContent = '';
  }

  // ------------------------------------------------------------------
  // 4) Submit handler — fetches the price for (iata, cabin, season,
  //    currency) and renders the result card.
  // ------------------------------------------------------------------
  form.addEventListener('submit', (ev) => {
    ev.preventDefault();
    clearError();
    const iata = resolveIata(toInput.value);
    if (!iata) {
      showError('Pick a destination from the list. Start typing to filter.');
      toInput.focus();
      return;
    }

    const payload = {
      iata: iata,
      cabin: cabinSel.value,
      season: seasonInput.value,
      currency: currencyInput.value,
    };

    fetch('/api/how-many-avios/price', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json().then((j) => ({ status: r.status, body: j })))
      .then(({ body }) => {
        if (!body.ok) {
          const hint = body.available_cabins && body.available_cabins.length
            ? ' Available cabins for this route: ' + body.available_cabins.join(', ') + '.'
            : '';
          showError((body.errors && body.errors[0]) || 'No result.' + hint);
          return;
        }
        resultRoute.textContent = 'LHR \u2192 ' + body.iata + ' \u00b7 ' + body.cabin;
        resultSub.textContent   = body.season + ' \u00b7 one-way \u00b7 ' + body.destination;
        resultAmt.textContent   = body.label;
        result.hidden = false;
      })
      .catch(() => showError('Something went wrong contacting the server.'));
  });
})();
