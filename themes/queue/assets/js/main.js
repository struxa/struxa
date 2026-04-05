document.querySelectorAll(".theme-nav-toggle").forEach((btn) => {
  btn.addEventListener("click", () => {
    const nav = document.querySelector(".theme-nav");
    if (!nav) return;
    const open = nav.classList.toggle("is-open");
    btn.setAttribute("aria-expanded", open ? "true" : "false");
  });
});

/* data-reveal / .is-visible: archives used to depend on IntersectionObserver here; theme CSS no longer
   hides [data-reveal] before JS, so content stays visible without this script. */

(function initProductDetailBodyTabs() {
  function tabId() {
    return "pdbt-" + Math.random().toString(36).slice(2, 11);
  }

  document.querySelectorAll("[data-product-body-tabs]").forEach((wrap) => {
    const source = wrap.querySelector(".product-detail-body-tabs__source");
    if (!source) return;
    /* Body may already be server-rendered [tabs] shortcodes (see RichtextTabsShortcode). */
    if (source.querySelector("[data-cms-tabs]")) {
      return;
    }

    let splitHeading = source.querySelectorAll("h3[data-streamdown='heading-3']")[1];
    if (!splitHeading) {
      const h3s = source.querySelectorAll("h3");
      splitHeading = h3s[1] || null;
    }
    if (!splitHeading) return;

    const uid = tabId();
    const overviewPanelId = uid + "-overview";
    const stackPanelId = uid + "-stack";

    const overviewNodes = [];
    let node = source.firstChild;
    while (node) {
      const next = node.nextSibling;
      if (node === splitHeading) break;
      overviewNodes.push(node);
      node = next;
    }

    const stackNodes = [];
    node = splitHeading;
    while (node) {
      const next = node.nextSibling;
      stackNodes.push(node);
      node = next;
    }

    const bar = document.createElement("div");
    bar.className = "product-detail-body-tabs__bar";
    bar.setAttribute("role", "tablist");
    bar.setAttribute("aria-label", "Product details");

    const btnOverview = document.createElement("button");
    btnOverview.type = "button";
    btnOverview.className = "product-detail-body-tabs__tab is-active";
    btnOverview.setAttribute("role", "tab");
    btnOverview.id = uid + "-tab-overview";
    btnOverview.setAttribute("aria-controls", overviewPanelId);
    btnOverview.setAttribute("aria-selected", "true");
    btnOverview.tabIndex = 0;
    btnOverview.textContent = "Overview";

    const btnStack = document.createElement("button");
    btnStack.type = "button";
    btnStack.className = "product-detail-body-tabs__tab";
    btnStack.setAttribute("role", "tab");
    btnStack.id = uid + "-tab-stack";
    btnStack.setAttribute("aria-controls", stackPanelId);
    btnStack.setAttribute("aria-selected", "false");
    btnStack.tabIndex = -1;
    btnStack.textContent = "Stack";

    const panelsWrap = document.createElement("div");
    panelsWrap.className = "product-detail-body-tabs__panels";

    const proseClasses =
      "product-detail-body-tabs__panel blog-post-body cms-prose product-detail-prose theme-cms-body";

    const panelOverview = document.createElement("div");
    panelOverview.className = proseClasses;
    panelOverview.id = overviewPanelId;
    panelOverview.setAttribute("role", "tabpanel");
    panelOverview.setAttribute("aria-labelledby", btnOverview.id);

    const panelStack = document.createElement("div");
    panelStack.className = proseClasses;
    panelStack.id = stackPanelId;
    panelStack.setAttribute("role", "tabpanel");
    panelStack.setAttribute("aria-labelledby", btnStack.id);
    panelStack.hidden = true;

    overviewNodes.forEach((n) => panelOverview.appendChild(n));
    stackNodes.forEach((n) => panelStack.appendChild(n));

    bar.append(btnOverview, btnStack);
    panelsWrap.append(panelOverview, panelStack);
    source.replaceWith(bar, panelsWrap);

    function activate(which) {
      const showOverview = which === "overview";
      panelOverview.hidden = !showOverview;
      panelStack.hidden = showOverview;
      btnOverview.classList.toggle("is-active", showOverview);
      btnStack.classList.toggle("is-active", !showOverview);
      btnOverview.setAttribute("aria-selected", showOverview ? "true" : "false");
      btnStack.setAttribute("aria-selected", showOverview ? "false" : "true");
      btnOverview.tabIndex = showOverview ? 0 : -1;
      btnStack.tabIndex = showOverview ? -1 : 0;
    }

    btnOverview.addEventListener("click", () => activate("overview"));
    btnStack.addEventListener("click", () => activate("stack"));
  });
})();

/* [tabs] shortcodes → server-rendered .product-detail-body-tabs[data-cms-tabs] */
(function initCmsTabsShortcode() {
  document.querySelectorAll("[data-cms-tabs]").forEach((wrap) => {
    const buttons = Array.from(wrap.querySelectorAll(".product-detail-body-tabs__tab"));
    const panels = Array.from(wrap.querySelectorAll(".product-detail-body-tabs__panel[role='tabpanel']"));
    if (buttons.length < 2 || panels.length !== buttons.length) {
      return;
    }

    function activate(index) {
      buttons.forEach((btn, i) => {
        const on = i === index;
        btn.classList.toggle("is-active", on);
        btn.setAttribute("aria-selected", on ? "true" : "false");
        btn.tabIndex = on ? 0 : -1;
        const panel = panels[i];
        if (panel) {
          panel.hidden = !on;
        }
      });
    }

    buttons.forEach((btn, i) => {
      btn.addEventListener("click", () => activate(i));
    });
  });
})();
