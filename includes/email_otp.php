<?php
/**
 * includes/email_otp.php
 *
 * Email-based OTP for 2FA.
 * OTP is 6 digits, valid for 10 minutes, single-use.
 * Stored in email_otp_tokens table.
 */

declare(strict_types=1);

class EmailOTP
{
    private const EXPIRY_MINUTES = 10;
    private const LENGTH         = 6;

    // ── Generate & store OTP ──────────────────────────────────

    /**
     * Generate a new OTP, store in DB, and return it (caller sends email).
     */
    public static function generate(int $userId): string
    {
        // Invalidate any existing OTPs for this user
        db()->prepare('DELETE FROM email_otp_tokens WHERE user_id=?')->execute([$userId]);

        $otp     = str_pad((string)random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60);
        $hash    = password_hash($otp, PASSWORD_BCRYPT);

        db()->prepare(
            'INSERT INTO email_otp_tokens (user_id, otp_hash, expires_at) VALUES (?,?,?)'
        )->execute([$userId, $hash, $expires]);

        return $otp;
    }

    // ── Verify OTP ────────────────────────────────────────────

    /**
     * Verify OTP. Returns true on success (and deletes token).
     * Returns false on wrong code or expired.
     */
    public static function verify(int $userId, string $code): bool
    {
        $code = trim(preg_replace('/\D/', '', $code));
        if (strlen($code) !== self::LENGTH) return false;

        $st = db()->prepare(
            'SELECT * FROM email_otp_tokens WHERE user_id=? AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch();

        if (!$row) return false;

        if (!password_verify($code, $row['otp_hash'])) return false;

        // Single-use — delete after success
        db()->prepare('DELETE FROM email_otp_tokens WHERE id=?')->execute([$row['id']]);
        return true;
    }

    // ── Resend throttle check ─────────────────────────────────

    /**
     * Returns seconds remaining before user can request another OTP.
     * Throttle: max 1 per 60 seconds.
     */
    public static function resendWait(int $userId): int
    {
        $st = db()->prepare(
            'SELECT created_at FROM email_otp_tokens WHERE user_id=? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) return 0;

        $wait = 60 - (time() - strtotime($row['created_at']));
        return max(0, $wait);
    }

    // ── Send OTP email ────────────────────────────────────────

    /**
     * Generate + send OTP email to user.
     * Returns ['ok'=>bool, 'error'=>string|null, 'wait'=>int]
     */
    public static function sendToUser(array $user): array
{
    $wait = self::resendWait((int)$user['id']);
    if ($wait > 0) {
        return ['ok' => false, 'error' => "Please wait {$wait} seconds before requesting a new code.", 'wait' => $wait];
    }

    $otp     = self::generate((int)$user['id']);
    $name    = $user['full_name'] ?? $user['username'] ?? 'User';
    $email   = $user['email'] ?? '';
    $appname = defined('APP_NAME') ? APP_NAME : 'GreatHost VPS';

    $subject = "{$appname} — Your Login Verification Code";

    try {
        // ✅ FIX: Use send_otp_email() instead of send_mail_smtp()
        require_once __DIR__ . '/mailer_invoice.php';
        
        $result = send_otp_email($email, $name, $otp, $subject);
        
        if ($result === true) {
            return ['ok' => true, 'error' => null, 'wait' => 60];
        } else {
            throw new Exception("send_otp_email returned false");
        }
        
    } catch (Throwable $e) {
        error_log('[EmailOTP] Mail error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine());
        return ['ok' => false, 'error' => 'Could not send email. Please try again or use your authenticator app.', 'wait' => 0];
    }
}

    // ── Email template ────────────────────────────────────────

    private static function buildEmailBody(string $name, string $otp, string $appname): string
    {
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 0">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <tr><td style="background:#16a34a;padding:28px 36px;text-align:center">
    <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:800">' . htmlspecialchars($appname) . '</h1>
    <p style="margin:4px 0 0;color:#bbf7d0;font-size:13px">Two-Factor Authentication</p>
  </td></tr>
  <tr><td style="padding:36px">
    <p style="margin:0 0 12px;font-size:15px;color:#374151">Hi <strong>' . htmlspecialchars($name) . '</strong>,</p>
    <p style="margin:0 0 22px;font-size:14px;color:#6b7280;line-height:1.6">
      Your login verification code is:
    </p>
    <div style="text-align:center;margin:24px 0">
      <div style="display:inline-block;background:#f0fdf4;border:2px dashed #16a34a;border-radius:12px;padding:16px 40px">
        <span style="font-size:36px;font-weight:900;letter-spacing:10px;color:#15803d;font-family:monospace">' . $otp . '</span>
      </div>
    </div>
    <p style="margin:0 0 10px;font-size:13px;color:#6b7280;text-align:center">
      ⏱ This code expires in <strong>10 minutes</strong> and can only be used once.
    </p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
    <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.6">
      If you didn\'t try to log in, please change your password immediately.<br>
      Do not share this code with anyone.
    </p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
    }
}
