<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Password hashing (Argon2id when this PHP has it, bcrypt otherwise) plus the password policy (NIST 800-63B oriented, same rules as the
 * Node version) and the constant-time "no such user" path.
 */
final class Password
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 128;

    private const COMMON = 'password,password1,password12,password123,password1234,passw0rd,p@ssw0rd,qwerty,qwerty123,qwertyuiop,12345678,123456789,1234567890,0123456789,11111111,00000000,iloveyou,letmein,welcome,welcome1,welcome123,admin,admin123,administrator,abc12345,abcd1234,monkey,dragon,football,baseball,sunshine,princess,master,shadow,superman,trustno1,changeme,changeme123,default,login,access,whatever,hello123,freedom,computer,internet,000000,654321,66666666,987654321,1q2w3e4r,1qaz2wsx,zaq12wsx,qazwsxedc,asdfghjkl,asdf1234,zxcvbnm,zxcvbnm123,test1234,testtest,secret,secret123,anibros,anibros123,crm12345,realestate';

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function options(): array
    {
        if (self::algo() === PASSWORD_BCRYPT) return ['cost' => Config::isTest() ? 4 : 12];
        // Shared hosting has limited memory per PHP process: 32 MiB x 3 passes keeps a sign-in well under a second.
        return Config::isTest() ? ['memory_cost' => 4096, 'time_cost' => 2, 'threads' => 1] : ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo(), self::options());
    }

    public static function verify(string $password, string $hash): bool
    {
        if (mb_strlen($password) > self::MAX_LENGTH) return false;
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo(), self::options());
    }

    /** Spend the same time as a real verification when no such user exists. Always false. */
    public static function verifyAgainstDummy(string $password): bool
    {
        static $dummy = null;
        $dummy ??= self::hash('dummy-password-for-timing-equalisation');
        self::verify($password, $dummy);
        return false;
    }

    /** @return string[] problems (empty = acceptable) */
    public static function problems(string $password, ?string $email = null, ?string $name = null): array
    {
        $p = [];
        $len = mb_strlen($password);
        if ($len < self::MIN_LENGTH) $p[] = 'Use at least ' . self::MIN_LENGTH . ' characters';
        if ($len > self::MAX_LENGTH) $p[] = 'Use at most ' . self::MAX_LENGTH . ' characters';
        $lower = mb_strtolower($password);
        if (in_array($lower, explode(',', self::COMMON), true)) $p[] = 'This password is too common';
        $local = $email !== null ? mb_strtolower(explode('@', $email)[0] ?? '') : '';
        if (mb_strlen($local) >= 4 && str_contains($lower, $local)) $p[] = 'Do not include your email address';
        foreach (preg_split('/\s+/', mb_strtolower((string) $name)) ?: [] as $part) {
            if (mb_strlen($part) >= 4 && str_contains($lower, $part)) { $p[] = 'Do not include your name'; break; }
        }
        if ($len > 0 && preg_match('/^(.)\1+$/us', $password)) $p[] = 'Do not repeat a single character';
        $classes = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $re) if (preg_match($re, $password)) $classes++;
        if ($len < 16 && $classes < 2) $p[] = 'Mix letters with numbers or symbols, or use a longer passphrase';
        return $p;
    }
}
