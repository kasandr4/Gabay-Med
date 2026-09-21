<?php
// mail-diagnose.php  --  TEMPORARY TROUBLESHOOTING PAGE. DELETE IT WHEN YOU'RE DONE.
//
// Walks through every step of sending a Gmail message and stops at the first
// one that fails, saying in plain words what went wrong. It runs inside the web
// server (Apache), so it sees exactly what forgot-password.php sees - unlike
// `php scripts/test-mailer.php`, which may use a different php.ini.
//
//   http://localhost/GabayMed/mail-diagnose.php            checks the connection + Gmail login (sends nothing)
//   http://localhost/GabayMed/mail-diagnose.php?send=1     also sends one real test email to GMAIL_ADDRESS
//
// Only works from this computer (localhost). Passwords are never printed.

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This page only works from the same computer that runs the site.');
}

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/mailer.php';

$results = [];
$stop = false;

function step(string $label, bool $ok, string $detail = '', string $hint = ''): void
{
    global $results, $stop;
    $results[] = compact('label', 'ok', 'detail', 'hint');
    if (!$ok) {
        $stop = true;
    }
}

function read_reply($socket): array
{
    $lines = [];
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            return [0, implode(' | ', $lines) ?: '(connection closed or timed out)'];
        }
        $lines[] = trim($line);
        $done = isset($line[3]) && $line[3] === ' ';
    } while (!$done);
    return [(int) substr($lines[0], 0, 3), implode(' | ', $lines)];
}

// ---- 1. environment ------------------------------------------------------
$address = trim((string) getenv('GMAIL_ADDRESS'));
$rawPass = (string) getenv('GMAIL_APP_PASSWORD');
$pass = str_replace(' ', '', trim($rawPass));

$ini = php_ini_loaded_file() ?: '(none loaded)';
$cafile = ini_get('openssl.cafile') ?: '(empty)';
$cainfo = ini_get('curl.cainfo') ?: '(empty)';
step(
    'PHP environment',
    extension_loaded('openssl'),
    'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ")\nphp.ini: {$ini}\nopenssl.cafile: {$cafile}\ncurl.cainfo: {$cainfo}",
    'The openssl extension is off. In php.ini enable extension=openssl and restart Apache.'
);

$masked = $address !== '' ? substr($address, 0, 2) . '***@' . (substr(strrchr($address, '@') ?: '@?', 1)) : '(not set)';
step(
    '.env settings',
    $address !== '' && $pass !== '',
    "GMAIL_ADDRESS: {$masked}\nGMAIL_APP_PASSWORD: " . ($pass !== '' ? strlen($pass) . ' characters after removing spaces (a Google app password is exactly 16)' : '(not set)'),
    'GMAIL_ADDRESS and GMAIL_APP_PASSWORD must both be in the .env file in the project root (same folder as login.php), one per line, no quotes. Restart Apache after editing .env if nothing changes.'
);
if (!$stop && strlen($pass) !== 16) {
    step(
        'App password length',
        false,
        'It is ' . strlen($pass) . ' characters, not 16.',
        'That is not a Google app password - possibly your normal Gmail password. Create one at myaccount.google.com/apppasswords (needs 2-Step Verification on).'
    );
}

$socket = null;
if (!$stop) {
    // ---- 2. DNS + connection ---------------------------------------------
    $ips = @gethostbynamel('smtp.gmail.com') ?: [];
    step(
        'Find smtp.gmail.com',
        !empty($ips),
        empty($ips) ? 'DNS lookup failed.' : implode(', ', $ips),
        'This computer cannot look up smtp.gmail.com - check the internet connection / DNS.'
    );
}
if (!$stop) {
    $t = microtime(true);
    $socket = @stream_socket_client('tcp://smtp.gmail.com:587', $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    $ms = (int) ((microtime(true) - $t) * 1000);
    step(
        'Connect to smtp.gmail.com port 587',
        (bool) $socket,
        $socket ? "connected in {$ms} ms" : "{$errstr} ({$errno}) after {$ms} ms",
        'Something is blocking outgoing port 587: Windows Firewall, antivirus "email protection" (Avast/Kaspersky/ESET scan or block SMTP), a school/office network, or the ISP. Try another network (e.g. phone hotspot) or temporarily disable the antivirus email shield.'
    );
    if ($socket) {
        stream_set_timeout($socket, 20);
    }
}

// ---- 3. SMTP conversation -------------------------------------------------
if (!$stop) {
    [$code, $text] = read_reply($socket);
    step('Server greeting', $code === 220, $text, 'Gmail did not greet us normally - the connection is probably being intercepted (antivirus / proxy).');
}
if (!$stop) {
    fwrite($socket, "EHLO gabaymed.local\r\n");
    [$code, $text] = read_reply($socket);
    step('EHLO', $code === 250, $text);
}
if (!$stop) {
    fwrite($socket, "STARTTLS\r\n");
    [$code, $text] = read_reply($socket);
    step('STARTTLS (ask for an encrypted connection)', $code === 220, $text);
}
if (!$stop) {
    $warn = '';
    set_error_handler(function ($no, $str) use (&$warn) {
        $warn = $str;
        return true;
    });
    $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    restore_error_handler();
    $hint = 'PHP could not verify Gmail\'s certificate. This is the most common XAMPP problem: '
        . '1) download https://curl.se/ca/cacert.pem to C:\\xampp\\php\\extras\\ssl\\cacert.pem  '
        . '2) in php.ini set  openssl.cafile="C:\\xampp\\php\\extras\\ssl\\cacert.pem"  and  curl.cainfo=<same path>  '
        . '3) restart Apache. (An antivirus that re-signs HTTPS/SMTP traffic causes the same error - disable its email shield.)';
    step('TLS handshake (certificate check)', (bool) $ok, $ok ? 'encrypted connection established' : ($warn ?: 'failed'), $hint);
}
if (!$stop) {
    fwrite($socket, "EHLO gabaymed.local\r\n");
    [$code, $text] = read_reply($socket);
    step('EHLO (again, encrypted)', $code === 250, $text);
}
if (!$stop) {
    fwrite($socket, "AUTH LOGIN\r\n");
    [$code, $text] = read_reply($socket);
    step('AUTH LOGIN', $code === 334, $text);
}
if (!$stop) {
    fwrite($socket, base64_encode($address) . "\r\n");
    [$code, $text] = read_reply($socket);
    step('Send Gmail address', $code === 334, $text);
}
if (!$stop) {
    fwrite($socket, base64_encode($pass) . "\r\n");
    [$code, $text] = read_reply($socket);
    $hint = 'Gmail refused the login. ' . (strpos($text, '534') === 0
        ? 'It says an app-specific password is required: turn on 2-Step Verification, then create an App Password at myaccount.google.com/apppasswords.'
        : 'Either the app password is wrong/typo\'d/revoked, or GMAIL_ADDRESS is not the account the app password was made for. Create a fresh App Password for exactly this Gmail address and paste it into .env (no quotes).');
    step('Login with the app password', $code === 235, $text, $hint);
}
if ($socket) {
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
}

// ---- 4. optional real send --------------------------------------------------
if (!$stop && isset($_GET['send'])) {
    $to = $address;
    $sent = send_email($to, 'Test', 'GabayMed mail test', '<p>If you can read this, GabayMed can send email through Gmail.</p>');
    step(
        'Send a real test email to ' . $masked,
        $sent,
        $sent ? 'Gmail accepted it - check that inbox (and spam).' : (function_exists('mailer_last_error') ? mailer_last_error() : 'see the PHP error log'),
        'The login worked but the message was refused - the detail above says why.'
    );
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Mail diagnosis - GabayMed</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 860px; margin: 30px auto; padding: 0 16px; color: #1f2937; }
        .row { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin: 10px 0; }
        .ok { border-left: 5px solid #16a34a; }
        .bad { border-left: 5px solid #dc2626; background: #fef2f2; }
        pre { white-space: pre-wrap; margin: 6px 0 0; font-size: 13px; color: #374151; }
        .hint { margin-top: 8px; font-weight: 600; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 10px 14px; border-radius: 8px; }
    </style>
</head>

<body>
    <h1>Mail diagnosis</h1>
    <p class="warn">Temporary page - delete <code>mail-diagnose.php</code> when you're done. It only works from this computer.</p>
    <?php foreach ($results as $r): ?>
        <div class="row <?= $r['ok'] ? 'ok' : 'bad' ?>">
            <strong><?= $r['ok'] ? '✔' : '✘' ?> <?= htmlspecialchars($r['label']) ?></strong>
            <?php if ($r['detail'] !== ''): ?><pre><?= htmlspecialchars($r['detail']) ?></pre><?php endif; ?>
            <?php if (!$r['ok'] && $r['hint'] !== ''): ?><div class="hint">What to do: <?= htmlspecialchars($r['hint']) ?></div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$stop): ?>
        <div class="row ok"><strong>✔ Everything checked works.</strong>
            <pre><?= isset($_GET['send']) ? 'The test email was sent.' : 'Add ?send=1 to the address to send a real test email.' ?></pre>
        </div>
    <?php endif; ?>
</body>

</html>
