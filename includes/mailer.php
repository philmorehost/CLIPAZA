<?php
declare(strict_types=1);

class Mailer {

    private string $smtpHost;
    private int    $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $encryption;
    private string $fromEmail;
    private string $fromName;

    public function __construct() {
        $this->smtpHost  = defined('SMTP_HOST')       ? SMTP_HOST       : '';
        $this->smtpPort  = defined('SMTP_PORT')        ? (int)SMTP_PORT  : 587;
        $this->smtpUser  = defined('SMTP_USER')        ? SMTP_USER       : '';
        $this->smtpPass  = defined('SMTP_PASS')        ? SMTP_PASS       : '';
        $this->encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls';
        $this->fromEmail = defined('ADMIN_EMAIL')      ? ADMIN_EMAIL     : $this->smtpUser;
        $this->fromName  = defined('SITE_NAME')        ? SITE_NAME       : 'Clipaza';
    }

    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        try {
            $this->queueEmail($to, $subject, $htmlBody);
            return $this->sendDirect($to, $subject, $htmlBody, $textBody);
        } catch (Throwable) {
            return false;
        }
    }

    private function sendDirect(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        if (empty($this->smtpHost)) {
            return $this->sendViaMail($to, $subject, $htmlBody);
        }

        $boundary = md5(uniqid('', true));
        $headers  = implode("\r\n", [
            'MIME-Version: 1.0',
            "From: {$this->fromName} <{$this->fromEmail}>",
            "Reply-To: {$this->fromEmail}",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            'X-Mailer: Clipaza/1.0',
        ]);

        $plain = $textBody ?: strip_tags($htmlBody);
        $body  = "--{$boundary}\r\n"
               . "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$plain}\r\n\r\n"
               . "--{$boundary}\r\n"
               . "Content-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n\r\n"
               . "--{$boundary}--";

        return mail($to, $subject, $body, $headers);
    }

    private function sendViaMail(string $to, string $subject, string $htmlBody): bool {
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
                 . "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        return mail($to, $subject, $htmlBody, $headers);
    }

    private function queueEmail(string $to, string $subject, string $body): void {
        try {
            $db = db();
            $stmt = $db->prepare(
                'INSERT INTO email_queue (to_email, subject, body) VALUES (?, ?, ?)'
            );
            $stmt->execute([$to, $subject, $body]);
        } catch (Throwable) {}
    }

    public function sendLoginNotification(string $to, string $username, string $ip): bool {
        $site    = htmlspecialchars(defined('SITE_NAME') ? SITE_NAME : 'Clipaza', ENT_QUOTES);
        $subject = "[{$site}] New login to your account";
        $html = "
        <div style='font-family:sans-serif;max-width:600px;margin:0 auto;background:#111;color:#fff;padding:32px;border-radius:12px;'>
            <h2 style='color:#CCFF00;margin-bottom:16px;'>{$site}</h2>
            <p>Hello <strong>" . htmlspecialchars($username, ENT_QUOTES) . "</strong>,</p>
            <p>A new login was detected on your account.</p>
            <p><strong>IP Address:</strong> " . htmlspecialchars($ip, ENT_QUOTES) . "</p>
            <p><strong>Time:</strong> " . htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES) . "</p>
            <p>If this was not you, please contact support immediately.</p>
            <hr style='border-color:#333;margin:24px 0;'>
            <p style='color:#888;font-size:0.85em;'>This is an automated message from {$site}.</p>
        </div>";
        return $this->send($to, $subject, $html);
    }
}
