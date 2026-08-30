<?php

declare(strict_types=1);

/**
 * A small SMTP client covering exactly what sending a plain-text
 * transactional email needs: connect (plain / implicit TLS / STARTTLS),
 * AUTH LOGIN, MAIL FROM / RCPT TO / DATA. Not a general-purpose mail
 * library — no attachments, no HTML multipart, no queueing. PHP's built-in
 * mail() shells out to a local MTA that a minimal container doesn't have
 * configured, and residential ISPs generally block outbound port 25
 * anyway, so this always talks to a relay the admin configures (Gmail,
 * their own provider's SMTP, etc.) rather than trying to send directly.
 */
final class Mailer
{
    private const TIMEOUT = 10;

    /** @return array{ok: bool, error: ?string} */
    public static function send(string $to, string $subject, string $body): array
    {
        $config = Settings::smtpConfig();
        if (empty($config['smtp_host']) || empty($config['smtp_from_email'])) {
            return ['ok' => false, 'error' => "SMTP n'est pas configuré (Réglages)"];
        }

        $host = $config['smtp_host'];
        $port = (int) ($config['smtp_port'] ?: 587);
        $encryption = $config['smtp_encryption'] ?: 'starttls'; // none | starttls | ssl
        $username = $config['smtp_username'] ?? '';
        $password = $config['smtp_password'] ?? '';
        $fromEmail = $config['smtp_from_email'];
        $fromName = $config['smtp_from_name'] ?: 'Codex';

        $transport = $encryption === 'ssl' ? "ssl://$host" : $host;
        $socket = @stream_socket_client(
            "$transport:$port",
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return ['ok' => false, 'error' => "Connexion SMTP impossible : $errstr ($errno)"];
        }
        stream_set_timeout($socket, self::TIMEOUT);

        try {
            [$code] = self::readResponse($socket);
            if ($code !== 220) {
                return ['ok' => false, 'error' => "Le serveur SMTP n'a pas répondu correctement à la connexion"];
            }

            $localHost = gethostname() ?: 'localhost';
            self::expect($socket, "EHLO $localHost", 250);

            if ($encryption === 'starttls') {
                self::expect($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'error' => 'Échec du démarrage TLS (STARTTLS)'];
                }
                self::expect($socket, "EHLO $localHost", 250);
            }

            if ($username !== '') {
                self::expect($socket, 'AUTH LOGIN', 334);
                self::expect($socket, base64_encode($username), 334);
                self::expect($socket, base64_encode($password), 235);
            }

            self::expect($socket, "MAIL FROM:<$fromEmail>", 250);
            self::expect($socket, "RCPT TO:<$to>", [250, 251]);
            self::expect($socket, 'DATA', 354);

            $message = self::buildMessage($fromEmail, $fromName, $to, $subject, $body);
            fwrite($socket, $message . "\r\n.\r\n");
            [$code, $msg] = self::readResponse($socket);
            if ($code !== 250) {
                return ['ok' => false, 'error' => "Le serveur a refusé le message : $msg"];
            }

            fwrite($socket, "QUIT\r\n");
            return ['ok' => true, 'error' => null];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            fclose($socket);
        }
    }

    private static function buildMessage(string $fromEmail, string $fromName, string $to, string $subject, string $body): string
    {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $lines = [
            'From: ' . self::encodeHeaderName($fromName) . " <$fromEmail>",
            "To: <$to>",
            "Subject: $encodedSubject",
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@codex>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body)),
        ];
        // Any line starting with a lone "." must be escaped per RFC 5321 dot-stuffing.
        return implode("\r\n", array_map(
            fn($line) => str_starts_with($line, '.') ? '.' . $line : $line,
            $lines
        ));
    }

    private static function encodeHeaderName(string $name): string
    {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }

    /** @param int|array<int> $expectedCode */
    private static function expect($socket, string $command, $expectedCode): void
    {
        fwrite($socket, $command . "\r\n");
        [$code, $msg] = self::readResponse($socket);
        $allowed = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        if (!in_array($code, $allowed, true)) {
            $safeCommand = str_starts_with($command, 'AUTH') || strlen($command) > 40 && !str_contains($command, ' ')
                ? '[identifiants]'
                : $command;
            throw new RuntimeException("Le serveur SMTP a répondu $code à \"$safeCommand\" : $msg");
        }
    }

    /** @return array{0: int, 1: string} */
    private static function readResponse($socket): array
    {
        $full = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new RuntimeException('Connexion SMTP interrompue');
            }
            $full .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($full, 0, 3);
        return [$code, trim($full)];
    }
}
