// page-transitions.js
// Shared fade transition between index.html, login.php, and register.php.
// Drop this in assets/js/ and include it on every page that should
// take part in the transition (it's safe to load on every page).

(function () {
  var prefersReducedMotion = window.matchMedia(
    '(prefers-reduced-motion: reduce)'
  ).matches;

  var FADE_MS = 280; // keep in sync with the CSS transition duration below

  /* ---------- Fade in on load ---------- */
  function fadeIn() {
    document.body.classList.add('pt-ready');
    // Two rAFs so the browser has painted the initial (hidden) state
    // before we flip the class — avoids a flash on fast page loads.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        document.body.classList.add('pt-visible');
      });
    });
  }

  if (prefersReducedMotion) {
    document.body.classList.add('pt-ready', 'pt-visible');
  } else {
    fadeIn();
  }

  /* ---------- Fade out before leaving the page ---------- */
  function isInternalNavigableLink(link) {
    if (!link || !link.href) return false;
    if (link.target && link.target !== '' && link.target !== '_self') return false;
    if (link.hasAttribute('download')) return false;

    var url;
    try {
      url = new URL(link.href, window.location.href);
    } catch (e) {
      return false;
    }

    // Different origin -> let the browser handle it normally.
    if (url.origin !== window.location.origin) return false;

    // Pure in-page anchor (e.g. "#services") -> no transition needed.
    if (url.pathname === window.location.pathname && url.hash) return false;

    // mailto:, tel:, javascript: etc. are filtered out by the origin check
    // above for mailto/tel since they won't parse as same-origin URLs.
    return true;
  }

  document.addEventListener('click', function (e) {
    if (prefersReducedMotion) return;

    // Ignore modified clicks (new tab, save-as, etc.) so browser defaults still work.
    if (e.defaultPrevented || e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

    var link = e.target.closest('a');
    if (!isInternalNavigableLink(link)) return;

    e.preventDefault();
    document.body.classList.remove('pt-visible');
    document.body.classList.add('pt-leaving');

    window.setTimeout(function () {
      window.location.href = link.href;
    }, FADE_MS);
  });

  // If the user navigates back via the bfcache, make sure the page
  // is visible again instead of stuck mid-fade.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      document.body.classList.remove('pt-leaving');
      document.body.classList.add('pt-ready', 'pt-visible');
    }
  });
})();
