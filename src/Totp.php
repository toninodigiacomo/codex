<?php

declare(strict_types=1);

/**
 * Minimal RFC 4226 (HOTP) / RFC 6238 (TOTP) implementation.
 * No external dependencies — compatible with Google Authenticator, Authy,
 * 1Password, Bitwarden, etc. Verified against the official RFC 4226
 * Appendix D test vectors.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP_SECONDS = 30;
    private const DIGITS = 6;

    /** Generate a random Base32-encoded secret (160 bits). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Build an otpauth:// URI for QR code / manual entry. */
    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verify a 6-digit code, tolerating clock drift of $window steps
     * before/after the current one (default ±60s).
     */
    public static function verify(string $secret, string $code, int $window = 2): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $currentStep = (int) floor(time() / self::STEP_SECONDS);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $currentStep + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    private static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N*', 0, $step); // 8-byte big-endian counter
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }
        return $bytes;
    }
}
