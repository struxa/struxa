/**
 * Struxa external-link click tracker.
 *
 * Sends a small JSON beacon to POST /track/external-link whenever a user clicks (or middle-clicks)
 * an outbound anchor on the storefront, so Admin → Site → External links can show top destinations,
 * top source pages, and recent clicks.
 *
 * Notes:
 *  - We never call preventDefault() — navigation/UX must be unaffected.
 *  - We try navigator.sendBeacon first (survives unload), then fall back to fetch keepalive.
 *  - Anchors with [data-no-track] (or any ancestor with [data-no-track]) are ignored.
 */
(function () {
  "use strict";

  if (typeof window === "undefined" || typeof document === "undefined") return;

  var ENDPOINT = "/track/external-link";

  function currentHost() {
    try {
      return (window.location && window.location.host) ? String(window.location.host).toLowerCase() : "";
    } catch (e) {
      return "";
    }
  }

  function hostsMatch(a, b) {
    a = (a || "").toLowerCase();
    b = (b || "").toLowerCase();
    if (a === b) return true;
    return a.replace(/^www\./i, "") === b.replace(/^www\./i, "");
  }

  function findAnchor(node) {
    var cur = node;
    while (cur && cur !== document && cur.nodeType === 1) {
      if (cur.tagName === "A") return cur;
      if (cur.hasAttribute && cur.hasAttribute("data-no-track")) return null;
      cur = cur.parentNode;
    }
    return null;
  }

  function isOutboundAnchor(anchor) {
    if (!anchor || !anchor.getAttribute) return false;
    if (anchor.closest && anchor.closest("[data-no-track]")) return false;
    var href = (anchor.getAttribute("href") || "").trim();
    if (href === "" || href.charAt(0) === "#") return false;
    var lower = href.toLowerCase();
    if (lower.indexOf("javascript:") === 0) return false;
    if (lower.indexOf("mailto:") === 0) return false;
    if (lower.indexOf("tel:") === 0) return false;
    if (!/^https?:\/\//i.test(href) && lower.indexOf("//") !== 0) return false;
    var destHost;
    try {
      destHost = new URL(anchor.href, window.location.href).host.toLowerCase();
    } catch (e) {
      return false;
    }
    if (!destHost) return false;
    return !hostsMatch(destHost, currentHost());
  }

  function linkText(anchor) {
    var t = "";
    try {
      if (anchor.getAttribute("aria-label")) {
        t = anchor.getAttribute("aria-label");
      } else if (typeof anchor.innerText === "string") {
        t = anchor.innerText;
      } else if (typeof anchor.textContent === "string") {
        t = anchor.textContent;
      }
    } catch (e) {}
    t = (t || "").replace(/\s+/g, " ").trim();
    if (t.length > 200) t = t.slice(0, 200);
    return t;
  }

  function sourceUrlWithoutFragment() {
    try {
      var u = new URL(window.location.href);
      u.hash = "";
      return u.toString();
    } catch (e) {
      return "";
    }
  }

  function sendBeacon(payload) {
    var json;
    try {
      json = JSON.stringify(payload);
    } catch (e) {
      return;
    }

    if (navigator && typeof navigator.sendBeacon === "function") {
      try {
        var blob = new Blob([json], { type: "application/json" });
        if (navigator.sendBeacon(ENDPOINT, blob)) return;
      } catch (e) {}
    }
    try {
      fetch(ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: json,
        keepalive: true,
        credentials: "same-origin",
        mode: "same-origin",
      }).catch(function () {});
    } catch (e) {}
  }

  function handleClick(event) {
    // Track left-clicks, middle-clicks, ctrl/cmd-clicks. Don't track right-click context menu opens.
    if (event.type === "click") {
      if (event.button !== undefined && event.button !== 0) return;
    } else if (event.type === "auxclick") {
      if (event.button !== 1) return;
    }
    var anchor = findAnchor(event.target);
    if (!anchor || !isOutboundAnchor(anchor)) return;

    var payload = {
      u: anchor.href,
      p: window.location.pathname || "/",
      s: sourceUrlWithoutFragment(),
      r: document.referrer || "",
      t: linkText(anchor),
    };
    sendBeacon(payload);
  }

  document.addEventListener("click", handleClick, true);
  document.addEventListener("auxclick", handleClick, true);
})();
