/**
 * Profile Page — Edit / Cancel / Save (AJAX)
 * Handles toggling the Personal Information + Address fields between
 * read-only and editable states, and submits changes via fetch().
 */
(function () {
    'use strict';

    const form       = document.getElementById('profileForm');
    const editBtn    = document.getElementById('editBtn');
    const saveBtn    = document.getElementById('saveBtn');
    const cancelBtn  = document.getElementById('cancelBtn');
    const toast      = document.getElementById('profileToast');

    const profileAvatar     = document.getElementById('profileAvatar');
    const profileFullName   = document.getElementById('profileFullName');
    const profileHeaderAddr = document.getElementById('profileHeaderAddress');

    if (!form) return;

    // All editable fields, including the address textarea (linked via form="profileForm")
    const editableFields = [
        document.getElementById('first_name'),
        document.getElementById('last_name'),
        document.getElementById('birthdate'),
        document.getElementById('email'),
        document.getElementById('phone_number'),
        document.getElementById('sex'),
        document.getElementById('philhealth_id'),
        document.getElementById('address'),
        document.getElementById('blood_type'),
        document.getElementById('civil_status'),
        document.getElementById('emergency_contact_name'),
        document.getElementById('emergency_contact_number'),
    ].filter(Boolean);

    // Snapshot of original values, used to restore on Cancel
    let originalValues = captureValues();

    function captureValues() {
        const snapshot = {};
        editableFields.forEach(field => {
            snapshot[field.name] = field.value;
        });
        return snapshot;
    }

    function restoreValues(snapshot) {
        editableFields.forEach(field => {
            if (snapshot.hasOwnProperty(field.name)) {
                field.value = snapshot[field.name];
            }
        });
    }

    function enterEditMode() {
        originalValues = captureValues();

        editableFields.forEach(field => field.disabled = false);
        form.classList.add('profile-form--editing');

        editBtn.hidden = true;
        saveBtn.hidden = false;
        cancelBtn.hidden = false;

        // Focus the first field for convenience
        editableFields[0] && editableFields[0].focus();
    }

    function exitEditMode() {
        editableFields.forEach(field => field.disabled = true);
        form.classList.remove('profile-form--editing');

        editBtn.hidden = false;
        saveBtn.hidden = true;
        cancelBtn.hidden = true;
    }

    function showToast(message, isError) {
        toast.textContent = message;
        toast.classList.toggle('error', !!isError);
        toast.classList.add('show');

        clearTimeout(showToast._timer);
        showToast._timer = setTimeout(() => {
            toast.classList.remove('show');
        }, 3500);
    }

    function setSaving(isSaving) {
        saveBtn.disabled = isSaving;
        saveBtn.textContent = isSaving ? 'Saving…' : 'Save Changes';
    }

    function updateHeaderDisplay(data) {
        if (profileAvatar)     profileAvatar.textContent = data.initials || '';
        if (profileFullName)   profileFullName.textContent = data.full_name || '';
        if (profileHeaderAddr) {
            const addr = (data.address || '').trim();
            profileHeaderAddr.innerHTML = addr === ''
                ? '<span class="empty">Not provided</span>'
                : addr;
        }
    }

    // ─── Event: Edit Profile ────────────────────────────────────────────────
    editBtn.addEventListener('click', enterEditMode);

    // ─── Event: Cancel ──────────────────────────────────────────────────────
    cancelBtn.addEventListener('click', function () {
        restoreValues(originalValues);
        exitEditMode();
    });

    // ─── Event: Save Changes (AJAX submit) ─────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        setSaving(true);

        const formData = new FormData(form);
        formData.append('action', 'update_profile');

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                return response.text().then(text => {
                    if (!response.ok) {
                        throw new Error('Server returned ' + response.status + ': ' + text.slice(0, 300));
                    }
                    try {
                        return JSON.parse(text);
                    } catch (parseErr) {
                        console.error('Raw server response (not valid JSON):', text);
                        throw new Error('Unexpected server response: ' + text.slice(0, 300));
                    }
                });
            })
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Profile updated successfully.', false);
                    updateHeaderDisplay(result.data || {});
                    exitEditMode();
                } else {
                    showToast(result.message || 'Unable to save changes. Please check your input.', true);
                }
            })
            .catch(err => {
                console.error('Profile save error:', err);
                showToast(err.message || 'Something went wrong. Please try again.', true);
            })
            .finally(() => {
                setSaving(false);
            });
    });

})();