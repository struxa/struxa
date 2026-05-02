const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

/* Footer year */
const yearEl = document.getElementById("year");
if (yearEl) yearEl.textContent = String(new Date().getFullYear());

/* Header scroll state */
const header = document.querySelector(".site-header");
const onScroll = () => {
  if (!header) return;
  header.classList.toggle("is-scrolled", window.scrollY > 24);
};
window.addEventListener("scroll", onScroll, { passive: true });
onScroll();

/* Mobile nav */
const menuToggle = document.querySelector(".menu-toggle");
const mobileNav = document.getElementById("mobile-nav");
if (menuToggle && mobileNav) {
  menuToggle.addEventListener("click", () => {
    const open = menuToggle.getAttribute("aria-expanded") === "true";
    menuToggle.setAttribute("aria-expanded", String(!open));
    mobileNav.hidden = open;
  });
  mobileNav.querySelectorAll("a").forEach((a) => {
    a.addEventListener("click", () => {
      menuToggle.setAttribute("aria-expanded", "false");
      mobileNav.hidden = true;
    });
  });
}

/* Hero spotlight — pointer position in CSS pixels */
const hero = document.querySelector(".hero");
const spotlight = document.getElementById("hero-spotlight");
if (hero && spotlight && !prefersReducedMotion) {
  let raf = 0;
  let lx = 0;
  let ly = 0;
  const update = () => {
    raf = 0;
    spotlight.style.transform = `translate(${lx}px, ${ly}px)`;
  };
  hero.addEventListener(
    "pointermove",
    (e) => {
      lx = e.clientX;
      ly = e.clientY;
      spotlight.classList.add("is-active");
      if (!raf) raf = requestAnimationFrame(update);
    },
    { passive: true }
  );
  hero.addEventListener("pointerleave", () => {
    spotlight.classList.remove("is-active");
  });
}

/* IntersectionObserver — staggered reveals */
const revealEls = document.querySelectorAll("[data-reveal]");
const io = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      const el = entry.target;
      const delay = Number(el.dataset.revealDelay) || 0;
      window.setTimeout(() => el.classList.add("is-visible"), delay);
      io.unobserve(el);
    });
  },
  { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
);

revealEls.forEach((el, i) => {
  if (prefersReducedMotion) {
    el.classList.add("is-visible");
  } else {
    el.dataset.revealDelay = String(i * 60);
    io.observe(el);
  }
});

/* Count-up metrics when visible */
function easeOutExpo(t) {
  return t >= 1 ? 1 : 1 - 2 ** (-10 * t);
}

function animateCount(el, target, duration, suffix = "") {
  const start = performance.now();
  const from = 0;
  const step = (now) => {
    const t = Math.min(1, (now - start) / duration);
    const v = Math.round(from + (target - from) * easeOutExpo(t));
    el.textContent = `${v}${suffix}`;
    if (t < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

document.querySelectorAll(".metric-value[data-count]").forEach((el) => {
  const target = Number(el.dataset.count);
  if (Number.isNaN(target)) return;
  const suffix = el.dataset.suffix || "";
  const obs = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        if (prefersReducedMotion) {
          el.textContent = `${target}${suffix}`;
        } else {
          animateCount(el, target, 1400, suffix);
        }
        obs.unobserve(el);
      });
    },
    { threshold: 0.4 }
  );
  obs.observe(el.closest(".metric-card") || el);
});

/* Card tilt — 3D tilt from pointer */
document.querySelectorAll("[data-tilt]").forEach((card) => {
  if (prefersReducedMotion) return;
  const max = 7;
  card.addEventListener(
    "pointermove",
    (e) => {
      const r = card.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width - 0.5;
      const py = (e.clientY - r.top) / r.height - 0.5;
      const rx = (-py * max).toFixed(2);
      const ry = (px * max).toFixed(2);
      card.style.transform = `perspective(900px) rotateX(${rx}deg) rotateY(${ry}deg) translateZ(0)`;
      card.style.setProperty("--gx", `${(0.5 + px) * 100}%`);
      card.style.setProperty("--gy", `${(0.5 + py) * 100}%`);
    },
    { passive: true }
  );
  card.addEventListener("pointerleave", () => {
    card.style.transform = "";
  });
});

/* Marquee — duplicate track for seamless loop */
const track = document.getElementById("marquee-track");
if (track && !prefersReducedMotion) {
  track.innerHTML += track.innerHTML;
}

/* Drifting bokeh orbs — soft circles, no lines or grids */
const canvas = document.getElementById("ambient-canvas");
if (canvas) {
  const ctx = canvas.getContext("2d");
  if (!ctx) {
    canvas.remove();
  } else {
    let w = 0;
    let h = 0;
    let dpr = 1;
    let t0 = performance.now();

    const orbs = [
      { bx: 0.08, by: 0.22, wx: 0.11, wy: 0.09, fx: 0.19, fy: 0.16, ph: 0, r: 0.52, rgb: [139, 92, 246] },
      { bx: 0.9, by: 0.18, wx: 0.09, wy: 0.11, fx: 0.14, fy: 0.21, ph: 1.4, r: 0.46, rgb: [34, 211, 238] },
      { bx: 0.52, by: 0.82, wx: 0.13, wy: 0.07, fx: 0.17, fy: 0.13, ph: 2.2, r: 0.44, rgb: [251, 113, 133] },
      { bx: 0.22, by: 0.62, wx: 0.08, wy: 0.12, fx: 0.11, fy: 0.18, ph: 0.6, r: 0.4, rgb: [167, 139, 250] },
      { bx: 0.78, by: 0.52, wx: 0.1, wy: 0.08, fx: 0.15, fy: 0.14, ph: 3.1, r: 0.42, rgb: [251, 191, 36] },
      { bx: 0.48, by: 0.1, wx: 0.07, wy: 0.06, fx: 0.12, fy: 0.11, ph: 4.5, r: 0.36, rgb: [45, 212, 191] },
      { bx: 0.04, by: 0.52, wx: 0.06, wy: 0.09, fx: 0.13, fy: 0.1, ph: 1.9, r: 0.34, rgb: [244, 114, 182] },
      { bx: 0.65, by: 0.35, wx: 0.05, wy: 0.05, fx: 0.09, fy: 0.1, ph: 5.2, r: 0.28, rgb: [129, 140, 248] },
    ];

    function resize() {
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      w = window.innerWidth;
      h = window.innerHeight;
      canvas.width = Math.floor(w * dpr);
      canvas.height = Math.floor(h * dpr);
      canvas.style.width = `${w}px`;
      canvas.style.height = `${h}px`;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    resize();
    window.addEventListener("resize", resize, { passive: true });

    function draw(now) {
      const t = prefersReducedMotion ? 0 : (now - t0) * 0.001;
      ctx.clearRect(0, 0, w, h);
      ctx.globalCompositeOperation = "screen";

      const dim = Math.max(w, h);
      for (const o of orbs) {
        const ox = Math.sin(t * o.fx + o.ph) * o.wx;
        const oy = Math.cos(t * o.fy + o.ph * 0.7) * o.wy;
        const x = w * (o.bx + ox);
        const y = h * (o.by + oy);
        const rad = dim * o.r * 0.5;
        const [r, g, b] = o.rgb;
        const grd = ctx.createRadialGradient(x, y, 0, x, y, rad);
        grd.addColorStop(0, `rgba(${r},${g},${b},0.2)`);
        grd.addColorStop(0.35, `rgba(${r},${g},${b},0.07)`);
        grd.addColorStop(0.65, `rgba(${r},${g},${b},0.02)`);
        grd.addColorStop(1, "transparent");
        ctx.fillStyle = grd;
        ctx.fillRect(0, 0, w, h);
      }

      ctx.globalCompositeOperation = "source-over";

      if (!prefersReducedMotion) {
        requestAnimationFrame(draw);
      }
    }

    if (prefersReducedMotion) {
      draw(performance.now());
    } else {
      requestAnimationFrame(draw);
    }
  }
}

/* Nav pills: reflect which section is in view */
const sectionIds = ["work", "showcase", "stack", "contact"];
const navLinksForScroll = document.querySelectorAll("a.nav-link[href^='#']");

function updateNavFromScroll() {
  if (!header || navLinksForScroll.length === 0) return;
  const offset = header.offsetHeight + 48;
  const y = window.scrollY + offset;
  const workEl = document.getElementById("work");
  const enterWork = workEl ? workEl.offsetTop - 100 : 480;

  let activeId = null;
  if (window.scrollY + offset >= enterWork) {
    for (const id of sectionIds) {
      const el = document.getElementById(id);
      if (el && el.offsetTop <= y) activeId = id;
    }
  }

  const maxScroll = document.documentElement.scrollHeight - window.innerHeight - 32;
  if (window.scrollY >= maxScroll) {
    activeId = "contact";
  }

  navLinksForScroll.forEach((a) => {
    const href = a.getAttribute("href");
    if (!href || href[0] !== "#") return;
    const id = href.slice(1);
    const match = id === activeId;
    a.classList.toggle("is-active", match);
    if (match) a.setAttribute("aria-current", "location");
    else a.removeAttribute("aria-current");
  });
}

window.addEventListener("scroll", updateNavFromScroll, { passive: true });
updateNavFromScroll();
