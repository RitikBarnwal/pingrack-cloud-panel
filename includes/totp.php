<?php
/**
 * includes/totp.php — Pure-PHP TOTP (RFC 6238), no external library needed.
 * Compatible with Google Authenticator, Authy, and any TOTP app.
 */
declare(strict_types=1);

class TOTP
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS       = 6;
    private const PERIOD       = 30;
    private const ALGORITHM    = 'sha1';
    private const WINDOW       = 1;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function generate(string $secret, int $timestamp = 0): string
    {
        $timestamp = $timestamp ?: time();
        return self::hotp($secret, (int)floor($timestamp / self::PERIOD));
    }

    public static function verify(string $secret, string $code, int $timestamp = 0): bool
    {
        $code = trim($code);
        $timestamp = $timestamp ?: time();
        if (!preg_match('/^\d{6}$/', $code)) return false;
        $counter = (int)floor($timestamp / self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $code)) return true;
        }
        return false;
    }

    public static function getQrUrl(string $secret, string $email, string $issuer): string
    {
        $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
             . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
        return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($uri);
    }

    public static function getOtpAuthUri(string $secret, string $email, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
             . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
             . '&digits=6&period=30';
    }

    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $codes);
    }

    public static function useRecoveryCode(string $stored, string $input): array
    {
        $hashed = json_decode($stored, true);
        if (!is_array($hashed)) return [false, $stored];
        $input = strtoupper(preg_replace('/\s/', '', $input));
        foreach ($hashed as $i => $hash) {
            if (password_verify($input, $hash)) {
                array_splice($hashed, $i, 1);
                return [true, json_encode($hashed)];
            }
        }
        return [false, $stored];
    }

    private static function hotp(string $secret, int $counter): string
    {
        $key   = self::base32Decode($secret);
        $msg   = pack('N*', 0) . pack('N*', $counter);
        $hash  = hash_hmac(self::ALGORITHM, $msg, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $otp = (
            ((ord($hash[$offset])   & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) <<  8) |
            ((ord($hash[$offset+3]) & 0xFF))
        ) % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $out = ''; $n = 0; $bits = 0;
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $n = ($n << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::BASE32_CHARS[($n >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) $out .= self::BASE32_CHARS[($n << (5 - $bits)) & 0x1F];
        return $out;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper($base32);
        $out = ''; $n = 0; $bits = 0;
        for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
            $val = strpos(self::BASE32_CHARS, $base32[$i]);
            if ($val === false) continue;
            $n = ($n << 5) | $val;
            $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($n >> $bits) & 0xFF); }
        }
        return $out;
    }
}
