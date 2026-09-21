<?php
// includes/mailer.php
// Minimal native-PHP SMTP client for sending mail through Gmail.
//
// Why hand-rolled instead of PHPMailer: this project is deliberately
// Composer-free / no-external-libraries (see config/env.php's own header
// comment), and PHP's built-in mail() function can't authenticate to
// Gmail's SMTP server at all (it only talks to a local MTA, no AUTH
// support) - so some SMTP client is unavoidable. This implements just
// enough of RFC 5321 (EHLO, STARTTLS, AUTH LOGIN, MAIL FROM/RCPT
// TO/DATA) to talk to smtp.gmail.com, nothing more.
//
// Setup (one-time):
//   1. The sending Gmail account needs 2-Step Verification turned on.
//   2. Generate an App Password: myaccount.google.com/apppasswords
//      (this is a 16-character password made JUST for this app - not
//      your real Gmail password, and it's what lets AUTH LOGIN work
//      without OAuth2, which would need a whole separate library).
//   3. Add to your .env (see .env.example):
//        GMAIL_ADDRESS=youraddress@gmail.com
//        GMAIL_APP_PASSWORD=xxxxxxxxxxxxxxxx
//
// Usage: require_once 'includes/mailer.php'; then send_email(...).
//
// XAMPP troubleshooting: if send_email() logs a STARTTLS/cert error,
// XAMPP's php.ini often has no CA bundle configured (openssl.cafile is
// empty by default on Windows). Download a current cacert.pem from
// curl.se/docs/caextract.html, then in php.ini set:
//   curl.cainfo = "C:\xampp\php\extras\ssl\cacert.pem"
//   openssl.cafile = "C:\xampp\php\extras\ssl\cacert.pem"
// and restart Apache. This is a one-time local-dev setup step, not
// something this script should work around by disabling verification.
//
// Failure handling: send_email() NEVER throws and never halts the
// calling script - a broken/missing mail config should degrade to "no
// email sent" (logged via error_log), not take down whatever page or
// script called it. Callers check the boolean return value.

require_once __DIR__ . '/../config/env.php';

/**
 * Remembers why the most recent send_email() call failed, so a caller (e.g.
 * forgot-password.php in APP_DEBUG_SHOW_CODE dev mode) can show the real
 * reason on screen instead of only in the PHP error log. Purely additive:
 * send_email()'s behaviour and return value are unchanged.
 * Call with no argument to read it, with a message to record (and log) it,
 * or with '' to clear it at the start of a send.
 */
function mailer_last_error(?string $message = null): string
{
    static $last = '';
    if ($message !== null) {
        $last = $message;
        if ($message !== '') {
            error_log('mailer.php: ' . $message);
        }
    }
    return $last;
}

/**
 * Sends one HTML email via Gmail's SMTP server.
 *
 * @param string $toEmail
 * @param string $toName    Used for the "To: Name <email>" header - purely cosmetic.
 * @param string $subject
 * @param string $bodyHtml  Raw HTML - keep it simple, this isn't a templating engine.
 * @return bool  true if the SMTP server accepted the message, false otherwise.
 */
function send_email(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
{
    $gmailAddress = getenv('GMAIL_ADDRESS');
    // Google displays app passwords in 4 groups with spaces; the spaces aren't part of the password.
    $gmailAppPassword = str_replace(' ', '', trim((string) getenv('GMAIL_APP_PASSWORD')));

    mailer_last_error('');
    if (!$gmailAddress || !$gmailAppPassword) {
        mailer_last_error('GMAIL_ADDRESS / GMAIL_APP_PASSWORD not set in .env - skipping email send.');
        return false;
    }

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        mailer_last_error("refusing to send to invalid address: {$toEmail}");
        return false;
    }

    $host = 'smtp.gmail.com';
    $port = 587;

    $socket = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        mailer_last_error("connection to {$host}:{$port} failed - {$errstr} ({$errno})");
        return false;
    }

    // Without this, a server that accepts the connection but then goes quiet would
    // make the page hang until PHP's max_execution_time.
    stream_set_timeout($socket, 20);

    try {
        smtp_expect($socket, '220'); // server greeting

        smtp_command($socket, "EHLO gabaymed.local", '250');

        smtp_command($socket, "STARTTLS", '220');
        // Capture PHP's warning text (e.g. "certificate verify failed") instead of
        // letting it print into the page, so the real reason reaches the log.
        $tlsWarning = '';
        set_error_handler(function ($no, $str) use (&$tlsWarning) {
            $tlsWarning = $str;
            return true;
        });
        $tlsOk = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        restore_error_handler();
        if (!$tlsOk) {
            throw new RuntimeException('STARTTLS negotiation failed' . ($tlsWarning !== '' ? ' - ' . $tlsWarning : '') . '.');
        }
        // Gmail requires EHLO again after upgrading to TLS.
        smtp_command($socket, "EHLO gabaymed.local", '250');

        smtp_command($socket, "AUTH LOGIN", '334');
        smtp_command($socket, base64_encode($gmailAddress), '334');
        smtp_command($socket, base64_encode($gmailAppPassword), '235');

        smtp_command($socket, "MAIL FROM:<{$gmailAddress}>", '250');
        smtp_command($socket, "RCPT TO:<{$toEmail}>", '250');
        smtp_command($socket, "DATA", '354');

        $fromHeader = "GabayMed <{$gmailAddress}>";
        $toHeader = $toName !== '' ? "{$toName} <{$toEmail}>" : $toEmail;
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $message = "From: {$fromHeader}\r\n"
            . "To: {$toHeader}\r\n"
            . "Subject: {$encodedSubject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "\r\n"
            . $bodyHtml . "\r\n"
            . ".\r\n"; // lone "." on its own line ends the DATA block, per RFC 5321

        fwrite($socket, $message);
        smtp_expect($socket, '250');

        smtp_command($socket, "QUIT", '221');
        fclose($socket);
        return true;
    } catch (RuntimeException $e) {
        mailer_last_error($e->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

/**
 * Sends one SMTP command and confirms the server's reply starts with
 * the expected status code.
 */
function smtp_command($socket, string $command, string $expectedCode): void
{
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $expectedCode);
}

/**
 * Reads an SMTP reply (handling multi-line "250-" continuation replies)
 * and throws if it doesn't start with the expected status code.
 */
function smtp_expect($socket, string $expectedCode): void
{
    $reply = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed unexpectedly while waiting for a reply.');
        }
        $reply .= $line;
        // A reply is complete once a line has the code followed by a
        // SPACE rather than a "-" (which marks a continuation line).
        $done = isset($line[3]) && $line[3] === ' ';
    } while (!$done);

    if (strpos($reply, $expectedCode) !== 0) {
        throw new RuntimeException("Unexpected SMTP reply (expected {$expectedCode}): " . trim($reply));
    }
}
