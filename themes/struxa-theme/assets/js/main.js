document.querySelectorAll('.st-nav-toggle').forEach((btn) => {
  btn.addEventListener('click', () => {
    const nav = document.getElementById('st-primary-nav');
    if (!nav) return;
    const open = nav.classList.toggle('is-open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  });
});

document.querySelectorAll('.st-nav__account').forEach((account) => {
  const trigger = account.querySelector('.st-nav__account-trigger');
  const menu = account.querySelector('.st-nav__account-menu');
  if (!trigger || !menu) return;

  const close = () => {
    account.classList.remove('is-open');
    trigger.setAttribute('aria-expanded', 'false');
  };

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = account.classList.toggle('is-open');
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  menu.querySelectorAll('a[role="menuitem"]').forEach((link) => {
    link.addEventListener('click', close);
  });

  document.addEventListener('click', (e) => {
    if (!account.contains(e.target)) close();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
});

(() => {
  const modal = document.getElementById('st-site-search');
  if (!modal) return;

  const openers = document.querySelectorAll('[data-st-search-open]');
  const closers = modal.querySelectorAll('[data-st-search-close]');
  const input = modal.querySelector('[data-st-search-input]');
  let lastFocus = null;

  const setOpen = (open) => {
    openers.forEach((btn) => btn.setAttribute('aria-expanded', open ? 'true' : 'false'));
    if (open) {
      lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');
      document.body.classList.add('st-search-modal-open');
      window.setTimeout(() => input?.focus(), 0);
    } else {
      modal.setAttribute('hidden', '');
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('is-open');
      document.body.classList.remove('st-search-modal-open');
      if (lastFocus) {
        lastFocus.focus();
        lastFocus = null;
      }
    }
  };

  openers.forEach((btn) => {
    btn.addEventListener('click', () => setOpen(true));
  });

  closers.forEach((el) => {
    el.addEventListener('click', () => setOpen(false));
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
      e.preventDefault();
      setOpen(false);
      return;
    }
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      const tag = document.activeElement?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || document.activeElement?.isContentEditable) {
        return;
      }
      e.preventDefault();
      setOpen(true);
    }
  });
})();

document.querySelectorAll('.st-github-repo__copy[data-copy-target]').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const id = btn.getAttribute('data-copy-target');
    const el = id ? document.getElementById(id) : null;
    const text = el ? el.textContent.trim() : '';
    if (!text) return;
    const done = () => {
      btn.classList.add('is-copied');
      const label = btn.querySelector('.st-github-repo__copy-label');
      if (label) label.textContent = 'Copied';
      window.setTimeout(() => {
        btn.classList.remove('is-copied');
        if (label) label.textContent = 'Copy';
      }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => {});
    }
  });
});
