/**
 * GabayMed - Sidebar toggle (mobile off-canvas)
 * Reused as-is across patient and doctor pages. Do not duplicate per page.
 */
(function () {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const hamburgerBtn = document.getElementById('hamburgerBtn');

  if (!sidebar || !overlay || !hamburgerBtn) return;

  function openSidebar() {
    sidebar.classList.add('sidebar-open');
    overlay.classList.add('overlay-visible');
  }

  function closeSidebar() {
    sidebar.classList.remove('sidebar-open');
    overlay.classList.remove('overlay-visible');
  }

  hamburgerBtn.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);

  // Close on Escape for keyboard users
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
  });

  // Close automatically if the viewport is resized back to desktop width
  window.addEventListener('resize', () => {
    if (window.innerWidth > 900) closeSidebar();
  });
})();
