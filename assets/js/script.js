// Oriental Mindoro Central District Hospital — interaction layer
// Handles: scroll-aware nav, anchor scrolling with header offset, and
// scroll-triggered reveal animation for sections/cards.

(function () {
  var prefersReducedMotion = window.matchMedia(
    '(prefers-reduced-motion: reduce)'
  ).matches;

  /* ---------- Sticky nav: subtle lift once the page scrolls ---------- */
  var header = document.querySelector('header');

  function updateHeaderState() {
    if (!header) return;
    if (window.scrollY > 8) {
      header.classList.add('is-scrolled');
    } else {
      header.classList.remove('is-scrolled');
    }
  }

  updateHeaderState();
  window.addEventListener('scroll', updateHeaderState, { passive: true });

  /* ---------- Mobile menu: hamburger toggle ---------- */
  var toggle = document.querySelector('.mobile-toggle');
  var mobileMenu = document.getElementById('mobile-menu');

  if (toggle && mobileMenu) {
    toggle.addEventListener('click', function () {
      var isOpen = mobileMenu.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close the menu after picking a link, and on resize back to desktop.
    mobileMenu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        mobileMenu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 900) {
        mobileMenu.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ---------- Anchor links: smooth scroll with header offset ---------- */
  var headerOffset = function () {
    return header ? header.getBoundingClientRect().height + 24 : 24;
  };

  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var id = link.getAttribute('href');
      if (!id || id === '#') return;

      var target = document.querySelector(id);
      if (!target) return;

      e.preventDefault();
      var top =
        target.getBoundingClientRect().top + window.scrollY - headerOffset();

      window.scrollTo({
        top: top,
        behavior: prefersReducedMotion ? 'auto' : 'smooth',
      });
    });
  });

  /* ---------- Scroll reveal: fade + rise as sections enter view ---------- */
  var revealSelectors = [
    '.step',
    '.service-card',
    '.feature-item',
    '.trust-strip-item',
    '.section-head',
    '.cta-card',
  ];

  var revealEls = document.querySelectorAll(revealSelectors.join(','));

  if (prefersReducedMotion || !('IntersectionObserver' in window)) {
    revealEls.forEach(function (el) {
      el.classList.add('is-visible');
    });
  } else {
    revealEls.forEach(function (el, i) {
      el.classList.add('reveal');
      // Small stagger within each section so groups of cards
      // settle in sequence rather than all at once.
      el.style.transitionDelay = (i % 4) * 70 + 'ms';
    });

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
    );

    revealEls.forEach(function (el) {
      observer.observe(el);
    });
  }
})();