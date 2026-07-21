<?php
/**
 * includes/mailer.php
 *
 * Thin wrapper around PHPMailer (already in composer.json).
 * Replaces / extends the original mailer with attachment support.
 */
declare(strict_types=1);

// ── Load PHPMailer robustly ───────────────────────────────────
// Prefer Composer's autoloader; fall back to direct src requires so the
// mailer keeps working regardless of how vendor/ was laid out. If PHPMailer
// is genuinely missing, log a clear message instead of a fatal require error.
(function () {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        foreach (['Exception', 'PHPMailer', 'SMTP'] as $f) {
            $p = __DIR__ . '/../vendor/phpmailer/phpmailer/src/' . $f . '.php';
            if (is_file($p)) require_once $p;
        }
    }
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('[mailer] PHPMailer not found. Run "composer install" in the app root to create vendor/.');
    }
})();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('send_mail')) {

/**
 * Send an HTML email.
 *
 * @param string $to
 * @param string $to_name
 * @param string $subject
 * @param string $html_body
 * @param array  $attachments  [['name'=>'file.pdf','content'=>$bytes,'type'=>'application/pdf'], ...]
 */
function send_mail(
    string $to,
    string $to_name,
    string $subject,
    string $html_body,
    array  $attachments = []
): bool {  // Change void to bool
    if (!class_exists(PHPMailer::class)) {
        error_log('[mailer] send_mail aborted: PHPMailer missing (run composer install).');
        return false;
    }
    try {
        $mail = new PHPMailer(true);
        //$mail->SMTPDebug = 2;
        $mail->isSMTP();
        $mail->Host        = SMTP_HOST;
        $mail->SMTPAuth    = true;
        $mail->Username    = SMTP_USERNAME;
        $mail->Password    = SMTP_PASS;
        $mail->SMTPSecure  = SMTP_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = SMTP_PORT;
        $mail->CharSet     = 'UTF-8';

        $from_name  = get_setting('SMTP_NAME', 'PingRack VPS');
        $from_email = get_setting('SMTP_FROM', 'support@pingrack.com');

        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to, $to_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = strip_tags($html_body);

        // Attachments
        foreach ($attachments as $att) {
            $mail->addStringAttachment(
                $att['content'],
                $att['name'],
                PHPMailer::ENCODING_BASE64,
                $att['type'] ?? 'application/octet-stream'
            );
        }

        $mail->send();
        return true;  // Add this - success
        
    } catch (MailException $e) {
        error_log('[mailer] Failed to send email to ' . $to . ': ' . $e->getMessage());
        return false;  // Add this - failure
    }
}

}