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
