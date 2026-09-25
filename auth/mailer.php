<?php
/**
 * Filmmaking tools — sending email.
 *
 * Through a cPanel email account: the tools sign in to its outgoing (SMTP)
 * server the way a phone's mail app would, so what they send is the same as
 * mail from that mailbox, signed and all. Set it up in the config file above
 * the web root (see config.sample.php, "Email"):
 *
 *   'mail_from'  => 'slate@creativemedia.church',   the account's address
 *   'smtp_host'  => 'mail.creativemedia.church',    as cPanel → Email
 *                                                   Accounts → Connect Devices
 *                                                   shows it
 *   'smtp_pass'  => '...',                          that account's password
 *
 * With mail_from set and no smtp_host, PHP's own mail() is used instead,
 * which on cPanel also works but is more likely to be taken for spam. With no
 * mail_from at all, nothing is sent and every tool carries on without email.
 *
 * Sending never throws and never holds up what the person was doing for
 * long: a failure is logged and reported as false, and the caller moves on.
 */

declare(strict_types=1);

/** Is sending set up at all? */
function fm_mail_enabled(): bool
{
    return fm_mail_from() !== '';
}

function fm_mail_from(): string
{
    $from = trim((string) (fm_config()['mail_from'] ?? ''));
    return fm_mail_address_ok($from) ? $from : '';
}

/** One plain address, and nothing that could start a new header line. */
function fm_mail_address_ok(string $address): bool
{
    return $address !== '' && strlen($address) <= 254
        && !preg_match('/[\r\n<>,;"]/', $address)
        && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
}

/** A header value in UTF-8 that survives any mail server. */
function fm_mail_header_text(string $text): string
{
    $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    return preg_match('/^[\x20-\x7E]*$/', $text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/** Absolute address of a path on this site, for links in an email. */
function fm_absolute_url(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $configured = rtrim(trim((string) (fm_config()['site_url'] ?? '')), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return $host === '' ? $path : (fm_is_https() ? 'https://' : 'http://') . $host . '/' . ltrim($path, '/');
}

/**
 * Send one email. $text is the plain version; $html, when given, is what
 * most mail apps show instead. Both are sent, so either can be read.
 *
 * @return string '' when sent, otherwise what went wrong (for logs and the
 *   test page, never shown to whoever triggered the email)
 */
function fm_send_mail(string $to, string $subject, string $text, string $html = ''): string
{
    $from = fm_mail_from();
    if ($from === '') {
        return 'Email is not set up (no mail_from in the config).';
    }
    if (!fm_mail_address_ok($to)) {
        return 'Not a usable address: ' . $to;
    }
    $config = fm_config();
    $name = (string) ($config['mail_from_name'] ?? 'Filmmaking Team');
    $domain = substr((string) strrchr($from, '@'), 1);

    $boundary = 'fm-' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . fm_mail_header_text($name) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . fm_mail_header_text($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        // Tells mail servers and vacation replies this was sent by a machine.
        'Auto-Submitted: auto-generated',
    ];
    $part = static fn(string $type, string $body) => "--$boundary\r\n"
        . "Content-Type: $type; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body)) . "\r\n";
    if ($html !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $text)) . "\r\n";
    }

    $host = trim((string) ($config['smtp_host'] ?? ''));
    try {
        if ($host === '') {
            // mail() adds To and Subject itself.
            $extra = array_values(array_filter($headers, static fn($h) => !preg_match('/^(To|Subject):/', $h)));
            $ok = @mail($to, fm_mail_header_text($subject), $body, implode("\r\n", $extra), '-f' . $from);
            $problem = $ok ? '' : 'PHP mail() refused the message.';
        } else {
            fm_smtp_send($config, $from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
            $problem = '';
        }
    } catch (Throwable $e) {
        $problem = $e->getMessage();
    }
    if ($problem !== '') {
        error_log('filmmaking mail to ' . $to . ' failed: ' . $problem);
    }
    return $problem;
}

/**
 * Hand one message to an SMTP server. Port 465 is TLS from the start, 587
 * upgrades with STARTTLS; smtp_secure overrides ('ssl', 'tls' or 'none').
 *
 * @throws RuntimeException naming the step that failed; never the password
 */
function fm_smtp_send(array $config, string $from, string $to, string $message): void
{
    $host = trim((string) $config['smtp_host']);
    $port = (int) ($config['smtp_port'] ?? 465);
    $secure = strtolower((string) ($config['smtp_secure'] ?? ($port === 465 ? 'ssl' : 'tls')));
    $user = (string) ($config['smtp_user'] ?? $from);
    $pass = (string) ($config['smtp_pass'] ?? '');

    $context = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
    ]]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port,
        $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if ($fp === false) {
        throw new RuntimeException("Could not connect to $host:$port ($errstr). Check smtp_host and smtp_port.");
    }
    stream_set_timeout($fp, 20);

    // Every reply, however many lines, and whether its code is one we want.
    $step = static function (string $label, ?string $send, array $want) use ($fp): string {
        if ($send !== null && fwrite($fp, $send . "\r\n") === false) {
            throw new RuntimeException("$label: connection lost.");
        }
        $reply = '';
        while (($line = fgets($fp, 2048)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($reply, 0, 3), $want, true)) {
            throw new RuntimeException("$label: " . ($reply === '' ? 'no answer' : trim($reply)));
        }
        return $reply;
    };

    try {
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (gethostname() ?: 'localhost')) ?: 'localhost';
        $step('Greeting', null, [220]);
        $features = $step('EHLO', 'EHLO ' . $me, [250]);
        if ($secure === 'tls') {
            $step('STARTTLS', 'STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS: could not start encryption. Try smtp_port 465.');
            }
            $features = $step('EHLO', 'EHLO ' . $me, [250]);
        }
        if ($pass !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $features)) {
                $step('Sign-in (check smtp_user and smtp_pass)', 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            } else {
                $step('Sign-in', 'AUTH LOGIN', [334]);
                $step('Sign-in (check smtp_user)', base64_encode($user), [334]);
                $step('Sign-in (check smtp_pass)', base64_encode($pass), [235]);
            }
        }
        $step('Sender (mail_from must be the account signed in as)', 'MAIL FROM:<' . $from . '>', [250]);
        $step('Recipient', 'RCPT TO:<' . $to . '>', [250, 251]);
        $step('DATA', 'DATA', [354]);
        // A line of just "." would end the message early, so every line
        // starting with one gets another.
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
        $step('Sending', str_replace("\n", "\r\n", rtrim((string) $data, "\n")) . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/**
 * A plain, readable email: a heading, a few lines, one button. Everything
 * given is text and is escaped here.
 */
function fm_mail_html(string $heading, array $lines, string $buttonLabel = '', string $buttonUrl = ''): string
{
    $h = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $out = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#F4F5F6;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1D242B">'
        . '<div style="max-width:520px;margin:0 auto;background:#FFFFFF;border:1px solid #DDE0E3;padding:28px">'
        . '<div style="font-family:Menlo,Consolas,monospace;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#9BA3AB;margin-bottom:10px">Filmmaking Team</div>'
        . '<div style="font-size:22px;font-weight:900;text-transform:uppercase;line-height:1.1;margin-bottom:16px">' . $h($heading) . '</div>';
    foreach ($lines as $line) {
        $out .= '<p style="font-size:15px;line-height:1.5;margin:0 0 12px">' . $h($line) . '</p>';
    }
    if ($buttonUrl !== '') {
        $out .= '<p style="margin:20px 0 0"><a href="' . $h($buttonUrl) . '" style="display:inline-block;background:#56C4E5;color:#FFFFFF;text-decoration:none;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding:12px 18px">'
            . $h($buttonLabel) . '</a></p>';
    }
    return $out . '<p style="font-size:12px;color:#9BA3AB;margin:24px 0 0">You can turn these emails off on your Account page.</p></div></body></html>';
}

/**
 * Who to email, by account id: address and name, leaving out anyone with no
 * address or who turned notices off.
 *
 * @param list<int> $ids
 * @return array<int, array{email: string, name: string}>
 */
function fm_mail_recipients(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    $stmt = fm_db()->prepare('SELECT id, email, display_name FROM users
        WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          AND is_active AND email_notices AND email IS NOT NULL');
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (fm_mail_address_ok((string) $row['email'])) {
            $out[(int) $row['id']] = ['email' => (string) $row['email'], 'name' => (string) $row['display_name']];
        }
    }
    return $out;
}
