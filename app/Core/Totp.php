<?php
declare(strict_types=1);

namespace App\Core;

/** TOTP (RFC 6238, HMAC-SHA1, 6 digits, 30 s) with replay protection via the last accepted time-step. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        $bits = 0; $value = 0; $out = '';
        foreach (str_split($bin) as $ch) {
            $value = (($value << 8) | ord($ch)) & 0xFFFFFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $bits = 0; $value = 0; $out = '';
        foreach (str_split(rtrim(strtoupper($s), '=')) as $ch) {
            $idx = strpos(self::ALPHABET, $ch);
            if ($idx === false) throw new \InvalidArgumentException('invalid base32');
            $value = (($value << 5) | $idx) & 0xFFFFFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 255);
                $bits -= 8;
            }
        }
        return $out;
    }

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20)); // 160 bit, RFC 4226 recommended
    }

    public static function otpauthUri(string $account, string $secret, string $issuer = 'Anibros CRM'): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function hotp(string $secretBin, int $counter): string
    {
        $msg = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $h = hash_hmac('sha1', $msg, $secretBin, true);
        $off = ord($h[19]) & 0xf;
        $code = ((ord($h[$off]) & 0x7f) << 24) | (ord($h[$off + 1]) << 16) | (ord($h[$off + 2]) << 8) | ord($h[$off + 3]);
        return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function timeStep(?int $nowMs = null): int
    {
        return intdiv((int) (($nowMs ?? Time::nowMs()) / 1000), 30);
    }

    /** Returns the accepted time-step, or null. Accepts +-$window steps to tolerate clock drift. */
    public static function verify(string $secretBase32, string $code, ?int $lastUsedStep = null, int $window = 1, ?int $nowMs = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $secret = self::base32Decode($secretBase32);
        $now = self::timeStep($nowMs);
        $accepted = null;
        for ($step = $now - $window; $step <= $now + $window; $step++) {
            // evaluate every candidate (no early exit) so timing does not reveal which step matched
            $ok = hash_equals(self::hotp($secret, $step), $code);
            if ($ok && ($lastUsedStep === null || $step > $lastUsedStep)) $accepted = $step;
        }
        return $accepted;
    }

    /** @return string[] */
    public static function recoveryCodes(int $n = 10): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $raw = substr(self::base32Encode(random_bytes(7)), 0, 10);
            $out[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $out;
    }

    public static function normalizeRecoveryCode(string $c): string
    {
        return strtoupper(preg_replace('/[\s-]/', '', $c));
    }
}
