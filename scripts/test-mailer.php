<?php
// scripts/test-mailer.php
//
// Standalone test for includes/mailer.php - sends ONE test email so you
// can confirm the Gmail SMTP setup actually works, without needing a
// real appointment sitting in the next-24h reminder window (which is
// what scripts/send-appointment-reminders.php requires).
//
// Usage:
//   php scripts/test-mailer.php you@example.com
//
// If no address is given, it defaults to sending to GMAIL_ADDRESS itself
// (i.e. the account emails itself) - convenient since that's guaranteed
// to be a real inbox you already have.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/mailer.php';

$to = $argv[1] ?? getenv('GMAIL_ADDRESS');

if (!$to) {
    echo "No recipient given, and GMAIL_ADDRESS isn't set in .env either." . PHP_EOL;
    echo "Usage: php scripts/test-mailer.php you@example.com" . PHP_EOL;
    exit(1);
}

echo "Sending a test email to {$to} ..." . PHP_EOL;

$sent = send_email(
    $to,
    'Test Recipient',
    'GabayMed reminder system - test email',
    '<p>If you\'re reading this, the Gmail SMTP setup in <code>includes/mailer.php</code> is working correctly.</p>'
        . '<p>This was sent by <code>scripts/test-mailer.php</code>, not the real reminder job - no appointment data was touched.</p>'
);

if ($sent) {
    echo "Success - check the inbox at {$to}." . PHP_EOL;
    exit(0);
}

echo "Failed to send. Check your PHP error log for the specific reason " .
    "(missing/wrong GMAIL_ADDRESS or GMAIL_APP_PASSWORD, or a TLS/cert " .
    "error - see includes/mailer.php's header comment for the XAMPP cert " .
    "fix if it's the latter)." . PHP_EOL;
exit(1);
