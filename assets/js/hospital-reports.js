// Hospital Reports UI interactions only. No backend calls.
(function () {
    'use strict';

    const backdrop = document.getElementById('reportPreviewModal');
    const viewer = backdrop ? backdrop.querySelector('.pdf-viewer') : null;
    const toolbarTitle = document.getElementById('reportPreviewTitle');
    const paperTitle = document.getElementById('pdfReportHeading');
    const paper = document.getElementById('pdfPaper');
    const closeBtn = document.getElementById('reportPreviewClose');
    const zoomInBtn = document.getElementById('pdfZoomIn');
    const zoomOutBtn = document.getElementById('pdfZoomOut');
    const fullscreenBtn = document.getElementById('pdfFullscreen');
    let zoom = 1;

    if (!backdrop || !viewer || !toolbarTitle || !paperTitle || !paper || !closeBtn) return;

    function setZoom(nextZoom) {
        zoom = Math.max(0.72, Math.min(1.18, nextZoom));
        paper.style.setProperty('--pdf-scale', zoom.toFixed(2));
    }

    function openPreview(title) {
        const reportTitle = title || 'Hospital Report';
        toolbarTitle.textContent = reportTitle;
        paperTitle.textContent = reportTitle;
        setZoom(1);
        viewer.classList.remove('is-fullscreen');
        backdrop.classList.add('active');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('report-modal-open');
    }

    function closePreview() {
        backdrop.classList.remove('active');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('report-modal-open');
    }

    document.addEventListener('click', (event) => {
        const previewBtn = event.target.closest('.report-preview-btn');
        if (previewBtn) {
            openPreview(previewBtn.getAttribute('data-report-title'));
            return;
        }

        if (event.target === backdrop || event.target.hasAttribute('data-close-report-preview')) {
            closePreview();
        }
    });

    closeBtn.addEventListener('click', closePreview);

    if (zoomInBtn) {
        zoomInBtn.addEventListener('click', () => setZoom(zoom + 0.08));
    }

    if (zoomOutBtn) {
        zoomOutBtn.addEventListener('click', () => setZoom(zoom - 0.08));
    }

    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', () => {
            viewer.classList.toggle('is-fullscreen');
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backdrop.classList.contains('active')) {
            closePreview();
        }
    });
})();
