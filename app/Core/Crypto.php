<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Field-level encryption for secrets stored in the database (integration tokens, webhook secrets, MFA seeds), token hashing and
 * constant-time comparison.
 *
 *   AES-256-GCM, random 96-bit IV, 128-bit tag. Token format  v1.<keyId>.<iv>.<tag>.<ciphertext>  (base64url segments), identical
 *   to the Node version. The AAD binds a ciphertext to its context so a value copied to another row fails authentication.
 *   Key rotation: secrets.field_keys = "k2:<hex>,k1:<hex>": first key encrypts, all keys decrypt.
 */
final class Crypto
{
    /** @var array<int,array{id:string,key:string}>|null */
    private static ?array $keys = null;

    /** @return array<int,array{id:string,key:string}> */
    private static function keys(): array
    {
        if (self::$keys !== null) return self::$keys;
        $raw = (string) Config::get('secrets.field_keys', '');
        if ($raw === '') {
            if (Config::isProduction()) throw new \RuntimeException('secrets.field_keys is not configured');
            $raw = 'dev:' . str_repeat('0', 64);
        }
        $keys = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$id, $hex] = str_contains($entry, ':') ? explode(':', $entry, 2) : ['k1', $entry];
            if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) throw new \RuntimeException('secrets.field_keys entry "' . $id . '" must be 64 hex chars (32 bytes)');
            $keys[] = ['id' => trim($id), 'key' => hex2bin($hex)];
        }
        if (!$keys) throw new \RuntimeException('secrets.field_keys is empty');
        if (Config::isProduction() && $keys[0]['key'] === str_repeat("\0", 32)) throw new \RuntimeException('An all-zero encryption key is not allowed in production');
        return self::$keys = $keys;
    }

    public static function b64(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? '' : $r;
    }

    public static function encrypt(string $plaintext, string $aad): string
    {
        $k = self::keys()[0];
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $k['key'], OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) throw new \RuntimeException('Encryption failed');
        return implode('.', ['v1', $k['id'], self::b64($iv), self::b64($tag), self::b64($ct)]);
    }

    public static function decrypt(string $token, string $aad): string
    {
        $p = explode('.', $token);
        if (count($p) !== 5 || $p[0] !== 'v1' || $p[1] === '' || $p[2] === '' || $p[3] === '' || $p[4] === '') throw new \RuntimeException('Malformed encrypted field');
        $key = null;
        foreach (self::keys() as $k) if ($k['id'] === $p[1]) $key = $k['key'];
        if ($key === null) throw new \RuntimeException('Unknown encryption key id "' . $p[1] . '"');
        $pt = openssl_decrypt(self::unb64($p[4]), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::unb64($p[2]), self::unb64($p[3]), $aad);
        if ($pt === false) throw new \RuntimeException('Encrypted field failed authentication'); // wrong key, wrong AAD or tampering: indistinguishable
        return $pt;
    }

    public static function needsRotation(string $token): bool
    {
        return (explode('.', $token)[1] ?? '') !== self::keys()[0]['id'];
    }

    /** SHA-256 hex of a token (what is stored instead of the token). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function safeEqual(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** HMAC key derived from the cookie secret (MFA challenges, signed state). */
    public static function hmac(string $purpose, string $data): string
    {
        return self::b64(hash_hmac('sha256', $data, $purpose . ':' . self::cookieSecret(), true));
    }

    public static function cookieSecret(): string
    {
        $s = (string) Config::get('secrets.cookie', '');
        if ($s === '') {
            if (Config::isProduction()) throw new \RuntimeException('secrets.cookie is not configured');
            return 'dev-only-cookie-secret-change-me-0123456789';
        }
        return $s;
    }

    public static function randomToken(int $bytes = 32): string
    {
        return Ids::token($bytes);
    }
}
