<?php
// includes/reference_number.php
// Formats an appointment's database ID into a human-readable reference
// number patients and front-desk staff can use to look up an appointment
// quickly — much faster than searching by name.
//
// Format: GM-YYYY-NNNNNN-SSSS
//   GM     = GabayMed
//   YYYY   = year the appointment was CREATED (not the visit date),
//            since that's what determines which "batch" of IDs it falls in
//   NNNNNN = appointment_id, zero-padded to 6 digits
//   SSSS   = 4-char signature (truncated HMAC-SHA256) over "GM-YYYY-NNNNNN",
//            keyed with a server-side secret. This is what stops someone
//            from guessing a valid reference by incrementing a known one
//            (e.g. going from GM-2026-000047-... to ...000048-...) — without
//            the secret, they can't produce a signature that matches.
//
// Example: appointment_id 47, created in 2026 -> "GM-2026-000047-A3F9"
//
// SECRET SOURCE: loaded from .env via config/env.php (REFERENCE_NUMBER_SECRET).
// Copy .env.example to .env and generate a real value with:
//   php -r "echo bin2hex(random_bytes(32));"
// If .env isn't set up yet, this falls back to a placeholder so local dev
// doesn't hard-crash — but that fallback is NOT safe to deploy with, since
// its value is visible to anyone with this source code. The warning below
// makes that impossible to miss instead of failing silently.
require_once __DIR__ . '/../config/env.php';

if (!defined('REFERENCE_NUMBER_SECRET')) {
    $secret = getenv('REFERENCE_NUMBER_SECRET');

    if ($secret === false || $secret === '') {
        trigger_error(
            'REFERENCE_NUMBER_SECRET is not set in .env — using an insecure ' .
                'placeholder. Reference numbers can be forged by anyone with this ' .
                'source code. Copy .env.example to .env and set a real secret ' .
                'before deploying.',
            E_USER_WARNING
        );
        $secret = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_BEFORE_PRODUCTION';
    }

    define('REFERENCE_NUMBER_SECRET', $secret);
}

function reference_signature($base)
{
    return strtoupper(substr(hash_hmac('sha256', $base, REFERENCE_NUMBER_SECRET), 0, 4));
}

function format_appointment_reference($appointment_id, $created_at)
{
    $year = date('Y', strtotime($created_at));
    $padded_id = str_pad($appointment_id, 6, '0', STR_PAD_LEFT);
    $base = "GM-{$year}-{$padded_id}";
    return "{$base}-" . reference_signature($base);
}

// Reverses format_appointment_reference(): given a reference number a
// patient or front-desk staff typed/scanned, extract the appointment_id
// — but only if the signature actually checks out. A string that merely
// LOOKS like a reference (right shape, wrong/guessed digits) will fail
// here and return null, since its signature won't match.
function parse_appointment_reference($reference)
{
    $reference = trim($reference);

    if (!preg_match('/^(GM-\d{4}-(\d{6}))-([A-F0-9]{4})$/', $reference, $matches)) {
        return null;
    }

    $base = $matches[1];
    $appointment_id = (int) $matches[2];
    $submitted_signature = $matches[3];

    // hash_equals() for constant-time comparison — avoids leaking timing
    // information about how much of the signature was correct.
    if (!hash_equals(reference_signature($base), $submitted_signature)) {
        return null;
    }

    return $appointment_id;
}

// Confirms a reference number actually belongs to the given appointment
// (i.e. re-derives it from the appointment's own data and compares),
// rather than just trusting that the id + signature parsed out of it are
// internally consistent. Catches a reference copied from a different
// record whose id/signature both happen to be individually valid.
function verify_appointment_reference($reference, $appointment_id, $created_at)
{
    return trim($reference) === format_appointment_reference($appointment_id, $created_at);
}
