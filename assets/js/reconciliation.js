// Reconciliation UI interactions only. No backend calls.
(function () {
    'use strict';

    // Static placeholder dataset, keyed by Reconciliation ID, matching the
    // rows already rendered in the Reconciliation Records table by
    // admin/reconciliation.php. Lets the View drawer and Compare modal
    // show record-specific content instead of one fixed sample, while
    // staying entirely client-side (no fetch, no backend).
    const RECORDS = {
        'REC-2026-0715-001': {
            type: 'Inventory Count', submittedBy: 'Rosario Padilla', department: 'Pharmacy',
            date: 'Jul 15, 2026', status: 'Pending',
            notes: 'Physical stock count for Pharmacy showed a variance against system records; submitted for Admin review.',
            po: '—', deliveryRef: '—',
            attachedNotes: 'Verify physical stock against medicine dispensing log before final approval.',
            inventory: [
                { item: 'Paracetamol 500mg', category: 'Medicines', system: '420 units', physical: '398 units', diff: '-22 units', diffClass: 'recon-diff-negative' },
                { item: 'Amoxicillin 500mg', category: 'Medicines', system: '210 units', physical: '210 units', diff: '0', diffClass: 'recon-diff-zero' },
            ],
            compare: {
                system: [
                    { label: 'Medicine', value: 'Paracetamol 500mg', cls: 'match' },
                    { label: 'Quantity', value: '420 units', cls: 'diff' },
                    { label: 'Price', value: '₱12,450.00', cls: 'review' },
                    { label: 'Supplier', value: 'MediSupply PH', cls: 'match' },
                    { label: 'Department', value: 'Pharmacy', cls: 'match' },
                ],
                submitted: [
                    { label: 'Medicine', value: 'Paracetamol 500mg', cls: 'match' },
                    { label: 'Quantity', value: '398 units', cls: 'diff' },
                    { label: 'Price', value: 'Needs receipt check', cls: 'review' },
                    { label: 'Supplier', value: 'MediSupply PH', cls: 'match' },
                    { label: 'Department', value: 'Pharmacy', cls: 'match' },
                ],
            },
        },
        'REC-2026-0715-002': {
            type: 'Delivery Receiving', submittedBy: 'Noel Bautista', department: 'Central Supply',
            date: 'Jul 15, 2026', status: 'Approved',
            notes: 'Delivery received against PO-2026-0714-006; quantities cross-checked against the purchase order.',
            po: 'PO-2026-0714-006', deliveryRef: 'DR-2026-0715-011',
            attachedNotes: 'Delivery matched the purchase order exactly; approved without adjustment.',
            inventory: [
                { item: 'Surgical Gloves (Box)', category: 'Medical Supplies', system: '150 boxes', physical: '150 boxes', diff: '0', diffClass: 'recon-diff-zero' },
                { item: 'IV Cannula 20G', category: 'Medical Supplies', system: '300 pcs', physical: '300 pcs', diff: '0', diffClass: 'recon-diff-zero' },
            ],
            compare: {
                system: [
                    { label: 'Item', value: 'Surgical Gloves (Box)', cls: 'match' },
                    { label: 'Quantity', value: '150 boxes', cls: 'match' },
                    { label: 'Supplier', value: 'CarePlus Distributors', cls: 'match' },
                    { label: 'PO Reference', value: 'PO-2026-0714-006', cls: 'match' },
                    { label: 'Department', value: 'Central Supply', cls: 'match' },
                ],
                submitted: [
                    { label: 'Item', value: 'Surgical Gloves (Box)', cls: 'match' },
                    { label: 'Quantity', value: '150 boxes', cls: 'match' },
                    { label: 'Supplier', value: 'CarePlus Distributors', cls: 'match' },
                    { label: 'PO Reference', value: 'PO-2026-0714-006', cls: 'match' },
                    { label: 'Department', value: 'Central Supply', cls: 'match' },
                ],
            },
        },
        'REC-2026-0714-018': {
            type: 'Stock Adjustment', submittedBy: 'Rosario Padilla', department: 'Pharmacy',
            date: 'Jul 14, 2026', status: 'Returned',
            notes: 'Write-off submitted for expired stock; returned to Pharmacy pending supporting documentation.',
            po: '—', deliveryRef: '—',
            attachedNotes: 'Please attach the expiry disposal log before resubmitting.',
            inventory: [
                { item: 'Cefalexin 500mg', category: 'Medicines', system: '85 units', physical: '60 units', diff: '-25 units', diffClass: 'recon-diff-negative' },
            ],
            compare: {
                system: [
                    { label: 'Medicine', value: 'Cefalexin 500mg', cls: 'match' },
                    { label: 'Quantity', value: '85 units', cls: 'diff' },
                    { label: 'Reason', value: 'Not specified', cls: 'review' },
                    { label: 'Department', value: 'Pharmacy', cls: 'match' },
                ],
                submitted: [
                    { label: 'Medicine', value: 'Cefalexin 500mg', cls: 'match' },
                    { label: 'Quantity', value: '60 units', cls: 'diff' },
                    { label: 'Reason', value: 'Expired stock write-off', cls: 'review' },
                    { label: 'Department', value: 'Pharmacy', cls: 'match' },
                ],
            },
        },
        'REC-2026-0714-014': {
            type: 'Monthly Inventory', submittedBy: 'Noel Bautista', department: 'Emergency Pharmacy',
            date: 'Jul 14, 2026', status: 'Completed',
            notes: 'End-of-month full inventory count for Emergency Pharmacy; reviewed and approved by Admin.',
            po: '—', deliveryRef: '—',
            attachedNotes: 'All counted items reconciled within tolerance. No further action needed.',
            inventory: [
                { item: 'Epinephrine 1mg/mL', category: 'Medicines', system: '48 units', physical: '48 units', diff: '0', diffClass: 'recon-diff-zero' },
                { item: 'Defibrillator Pads', category: 'Medical Equipment', system: '20 sets', physical: '19 sets', diff: '-1 set', diffClass: 'recon-diff-negative' },
            ],
            compare: {
                system: [
                    { label: 'Item', value: 'Epinephrine 1mg/mL', cls: 'match' },
                    { label: 'Quantity', value: '48 units', cls: 'match' },
                    { label: 'Department', value: 'Emergency Pharmacy', cls: 'match' },
                ],
                submitted: [
                    { label: 'Item', value: 'Epinephrine 1mg/mL', cls: 'match' },
                    { label: 'Quantity', value: '48 units', cls: 'match' },
                    { label: 'Department', value: 'Emergency Pharmacy', cls: 'match' },
                ],
            },
        },
        'REC-2026-0713-009': {
            type: 'Stock Adjustment', submittedBy: 'Rosario Padilla', department: 'Pharmacy',
            date: 'Jul 13, 2026', status: 'Pending',
            notes: 'Adjustment submitted for damaged packaging discovered during shelving.',
            po: '—', deliveryRef: '—',
            attachedNotes: 'Photos of damaged packaging attached for reference.',
            inventory: [
                { item: 'Salbutamol Inhaler', category: 'Medicines', system: '64 units', physical: '58 units', diff: '-6 units', diffClass: 'recon-diff-negative' },
            ],
            compare: {
                system: [
                    { label: 'Medicine', value: 'Salbutamol Inhaler', cls: 'match' },
                    { label: 'Quantity', value: '64 units', cls: 'diff' },
                    { label: 'Reason', value: 'Not specified', cls: 'review' },
                ],
                submitted: [
                    { label: 'Medicine', value: 'Salbutamol Inhaler', cls: 'match' },
                    { label: 'Quantity', value: '58 units', cls: 'diff' },
                    { label: 'Reason', value: 'Damaged packaging', cls: 'review' },
                ],
            },
        },
        'REC-2026-0712-004': {
            type: 'Delivery Receiving', submittedBy: 'Noel Bautista', department: 'Warehouse',
            date: 'Jul 12, 2026', status: 'Approved',
            notes: 'Bulk delivery received at Warehouse; verified against purchase order and approved.',
            po: 'PO-2026-0710-002', deliveryRef: 'DR-2026-0712-005',
            attachedNotes: 'Minor packaging damage noted on 1 unit, replacement requested from supplier separately.',
            inventory: [
                { item: 'Hospital Bed (Manual)', category: 'Medical Equipment', system: '10 units', physical: '10 units', diff: '0', diffClass: 'recon-diff-zero' },
            ],
            compare: {
                system: [
                    { label: 'Item', value: 'Hospital Bed (Manual)', cls: 'match' },
                    { label: 'Quantity', value: '10 units', cls: 'match' },
                    { label: 'Supplier', value: 'MedEquip Solutions', cls: 'match' },
                    { label: 'PO Reference', value: 'PO-2026-0710-002', cls: 'match' },
                ],
                submitted: [
                    { label: 'Item', value: 'Hospital Bed (Manual)', cls: 'match' },
                    { label: 'Quantity', value: '10 units', cls: 'match' },
                    { label: 'Supplier', value: 'MedEquip Solutions', cls: 'match' },
                    { label: 'PO Reference', value: 'PO-2026-0710-002', cls: 'match' },
                ],
            },
        },
    };

    const statusPillClass = {
        Pending: 'status-recon-pending', Approved: 'status-recon-approved',
        Returned: 'status-recon-returned', Completed: 'status-recon-completed',
    };

    const drawer = document.getElementById('reconDrawer');
    const drawerOverlay = document.getElementById('reconDrawerOverlay');
    const drawerTitle = document.getElementById('reconDrawerTitle');
    const drawerRef = document.getElementById('drawerRef');
    const drawerType = document.getElementById('drawerType');
    const drawerSubmittedBy = document.getElementById('drawerSubmittedBy');
    const drawerDepartment = document.getElementById('drawerDepartment');
    const drawerDate = document.getElementById('drawerDate');
    const drawerStatus = document.getElementById('drawerStatus');
    const drawerNotes = document.getElementById('drawerNotes');
    const drawerPo = document.getElementById('drawerPo');
    const drawerDeliveryRef = document.getElementById('drawerDeliveryRef');
    const drawerAttachedNotes = document.getElementById('drawerAttachedNotes');
    const drawerInventoryTable = document.getElementById('drawerInventoryTable');
    const drawerApproveBtn = document.getElementById('reconDrawerApprove');
    const drawerReturnBtn = document.getElementById('reconDrawerReturn');

    const compareModal = document.getElementById('compareModal');
    const compareTitle = document.getElementById('compareTitle');

    const pdfModal = document.getElementById('reconPdfPreview');
    const pdfViewer = pdfModal ? pdfModal.querySelector('.pdf-viewer') : null;
    const pdfPaper = document.getElementById('pdfPaper');
    let pdfZoom = 1;

    let currentDrawerRef = null;

    function anyOverlayOpen() {
        return document.querySelector('.recon-drawer.active, .compare-backdrop.active, .pdf-viewer-backdrop.active');
    }

    function lockBody() {
        document.body.classList.add('recon-overlay-open');
    }

    function unlockBodyIfClear() {
        if (!anyOverlayOpen()) document.body.classList.remove('recon-overlay-open');
    }

    // ============================================================
    // Toasts (Approve / Return for Revision feedback — UI only)
    // ============================================================

    function toast(message, isWarn) {
        const host = document.getElementById('reconToastHost');
        if (!host) return;
        const el = document.createElement('div');
        el.className = 'recon-toast' + (isWarn ? ' recon-toast-warn' : '');
        el.textContent = message;
        host.appendChild(el);
        requestAnimationFrame(() => el.classList.add('recon-toast-show'));
        setTimeout(() => {
            el.classList.remove('recon-toast-show');
            setTimeout(() => el.remove(), 250);
        }, 3400);
    }

    // ============================================================
    // View drawer
    // ============================================================

    function openDrawer(ref) {
        if (!drawer || !drawerOverlay) return;
        const data = RECORDS[ref];
        currentDrawerRef = ref;

        drawerTitle.textContent = 'Reconciliation Details — ' + ref;
        if (drawerRef) drawerRef.textContent = ref;

        if (data) {
            if (drawerType) drawerType.textContent = data.type;
            if (drawerSubmittedBy) drawerSubmittedBy.textContent = data.submittedBy;
            if (drawerDepartment) drawerDepartment.textContent = data.department;
            if (drawerDate) drawerDate.textContent = data.date;
            if (drawerStatus) {
                drawerStatus.textContent = data.status;
            }
            if (drawerNotes) drawerNotes.textContent = data.notes;
            if (drawerPo) drawerPo.textContent = data.po;
            if (drawerDeliveryRef) drawerDeliveryRef.textContent = data.deliveryRef;
            if (drawerAttachedNotes) drawerAttachedNotes.textContent = data.attachedNotes;

            if (drawerInventoryTable) {
                const tbody = drawerInventoryTable.querySelector('tbody');
                if (tbody) {
                    tbody.innerHTML = data.inventory.map((row) =>
                        '<tr><td>' + row.item + '</td><td>' + row.category + '</td><td>' + row.system +
                        '</td><td>' + row.physical + '</td><td class="' + row.diffClass + '">' + row.diff + '</td></tr>'
                    ).join('');
                }
            }

            // Approve is only meaningful while a record is still Pending —
            // matches the row-level "Approve" button, which the table only
            // shows for pending rows.
            if (drawerApproveBtn) drawerApproveBtn.disabled = data.status !== 'Pending';
            if (drawerReturnBtn) drawerReturnBtn.disabled = data.status !== 'Pending';
        }

        drawer.classList.add('active');
        drawerOverlay.classList.add('active');
        drawer.setAttribute('aria-hidden', 'false');
        lockBody();
    }

    function closeDrawer() {
        if (!drawer || !drawerOverlay) return;
        drawer.classList.remove('active');
        drawerOverlay.classList.remove('active');
        drawer.setAttribute('aria-hidden', 'true');
        unlockBodyIfClear();
    }

    drawerApproveBtn?.addEventListener('click', () => {
        if (!currentDrawerRef) return;
        toast(currentDrawerRef + ' approved. (UI only — not saved.)');
        closeDrawer();
    });

    drawerReturnBtn?.addEventListener('click', () => {
        if (!currentDrawerRef) return;
        toast(currentDrawerRef + ' returned to Pharmacy for revision. (UI only — not saved.)', true);
        closeDrawer();
    });

    // ============================================================
    // Compare Records modal
    // ============================================================

    function renderComparePanel(rows) {
        return '<dl>' + rows.map((r) =>
            '<div class="' + r.cls + '"><dt>' + r.label + '</dt><dd>' + r.value + '</dd></div>'
        ).join('') + '</dl>';
    }

    function openCompare(ref) {
        if (!compareModal) return;
        const data = RECORDS[ref];
        compareTitle.textContent = 'Compare Records — ' + ref;

        if (data && data.compare) {
            const systemPanel = compareModal.querySelector('.compare-panel:nth-child(1)');
            const submittedPanel = compareModal.querySelector('.compare-panel:nth-child(2)');
            if (systemPanel) systemPanel.innerHTML = '<h4>System Record</h4>' + renderComparePanel(data.compare.system);
            if (submittedPanel) submittedPanel.innerHTML = '<h4>Submitted Record</h4>' + renderComparePanel(data.compare.submitted);

            const allRows = data.compare.submitted;
            const matched = allRows.filter((r) => r.cls === 'match').length;
            const mismatched = allRows.filter((r) => r.cls === 'diff').length;
            const needsReview = allRows.filter((r) => r.cls === 'review').length;
            const overall = mismatched > 0 ? 'Mismatch Found' : (needsReview > 0 ? 'Needs Review' : 'Fully Matched');

            const summary = compareModal.querySelector('.compare-summary');
            if (summary) {
                summary.innerHTML =
                    '<div><span>Matched Fields</span><strong>' + matched + '</strong></div>' +
                    '<div><span>Mismatched Fields</span><strong>' + mismatched + '</strong></div>' +
                    '<div><span>Overall Status</span><strong>' + overall + '</strong></div>';
            }
        }

        compareModal.classList.add('active');
        compareModal.setAttribute('aria-hidden', 'false');
        lockBody();
    }

    function closeCompare() {
        if (!compareModal) return;
        compareModal.classList.remove('active');
        compareModal.setAttribute('aria-hidden', 'true');
        unlockBodyIfClear();
    }

    // ============================================================
    // PDF-style report preview
    // ============================================================

    function setPdfZoom(next) {
        if (!pdfPaper) return;
        pdfZoom = pdfZoom === 1 ? 0.82 : 1;
        if (typeof next === 'number') pdfZoom = next;
        pdfPaper.style.setProperty('--pdf-scale', pdfZoom.toFixed(2));
    }

    function openPdf() {
        if (!pdfModal) return;
        setPdfZoom(1);
        if (pdfViewer) pdfViewer.classList.remove('is-fullscreen');
        pdfModal.classList.add('active');
        pdfModal.setAttribute('aria-hidden', 'false');
        lockBody();
    }

    function closePdf() {
        if (!pdfModal) return;
        pdfModal.classList.remove('active');
        pdfModal.setAttribute('aria-hidden', 'true');
        unlockBodyIfClear();
    }

    // ============================================================
    // Wire everything up
    // ============================================================

    document.addEventListener('click', (event) => {
        const viewBtn = event.target.closest('.recon-view-btn');
        if (viewBtn) {
            openDrawer(viewBtn.getAttribute('data-ref') || 'Reconciliation Record');
            return;
        }

        const compareBtn = event.target.closest('.recon-compare-btn');
        if (compareBtn) {
            openCompare(compareBtn.getAttribute('data-ref') || 'Reconciliation Record');
            return;
        }

        if (event.target.id === 'openPdfPreviewBtn') openPdf();
    });

    document.getElementById('reconDrawerClose')?.addEventListener('click', closeDrawer);
    document.getElementById('reconDrawerCloseBottom')?.addEventListener('click', closeDrawer);
    drawerOverlay?.addEventListener('click', closeDrawer);
    document.getElementById('compareClose')?.addEventListener('click', closeCompare);
    compareModal?.addEventListener('click', (event) => {
        if (event.target === compareModal) closeCompare();
    });

    document.getElementById('pdfClose')?.addEventListener('click', closePdf);
    document.getElementById('pdfZoom')?.addEventListener('click', () => setPdfZoom());
    document.getElementById('pdfFullscreen')?.addEventListener('click', () => {
        if (pdfViewer) pdfViewer.classList.toggle('is-fullscreen');
    });
    pdfModal?.addEventListener('click', (event) => {
        if (event.target === pdfModal) closePdf();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        closeCompare();
        closePdf();
        closeDrawer();
    });
})();
