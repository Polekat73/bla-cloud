<?php
declare(strict_types=1);

namespace BlaCloud;

/**
 * Time-based one-time passwords (RFC 6238), compatible with Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password, Bitwarden, Aegis, etc.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1; // accept one step before/after for clock drift

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::B32, $c);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function codeAt(string $secretB32, int $step, string $algo = 'sha1', int $digits = self::DIGITS): string
    {
        $key  = self::base32Decode($secretB32);
        $msg  = pack('J', $step);
        $hash = hash_hmac($algo, $msg, $key, true);
        $off  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $bin  = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16)
              | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Verify a code. Returns the matched time-step (so callers can block replays), or null.
     * $lastStep: the last step already used by this user — codes at or before it are rejected.
     */
    public static function verify(string $secretB32, string $code, int $lastStep = 0, ?int $time = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::currentStep($time);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            if ($step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::codeAt($secretB32, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function uri(string $secretB32, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secretB32, 'issuer' => $issuer, 'algorithm' => 'SHA1',
            'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
