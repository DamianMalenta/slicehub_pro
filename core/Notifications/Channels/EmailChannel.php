<?php
declare(strict_types=1);

/**
 * EmailChannel — wysyłka email przez SMTP (PHPMailer).
 *
 * Credentials w sh_notification_channels.credentials_json:
 * {
 *   "host":       "smtp.gmail.com",
 *   "port":       587,
 *   "encryption": "tls",          // tls | ssl | "" (brak)
 *   "username":   "pizza@gmail.com",
 *   "password":   "app_password",
 *   "from_email": "pizza@gmail.com",
 *   "from_name":  "Pizza Forno"
 * }
 *
 * PHPMailer wymagany: composer require phpmailer/phpmailer
 * Fallback (gdy PHPMailer niedostępny): natywna funkcja mail() PHP.
 */
class EmailChannel implements ChannelInterface
{
    public static function getChannelType(): string
    {
        return 'email';
    }

    public function send(
        string $recipient,
        string $subject,
        string $body,
        array  $channelConfig,
        array  $ctx = []
    ): DeliveryResult {
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return DeliveryResult::fail("Invalid email recipient: '{$recipient}'");
        }

        $cred = $channelConfig['credentials'] ?? [];
        $fromEmail = (string)($cred['from_email'] ?? $cred['username'] ?? '');
        $fromName  = (string)($cred['from_name']  ?? 'Restauracja');

        // Zamień \n na <br> dla HTML email — prosta konwersja
        $htmlBody = '<html><body><div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . '</div></body></html>';

        // Próba 1: PHPMailer (preferowany)
        $phpMailerPaths = [
            dirname(dirname(dirname(__DIR__))) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
            dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php',
        ];

        foreach ($phpMailerPaths as $path) {
            if (file_exists($path)) {
                return $this->sendViaPHPMailer($recipient, $subject, $htmlBody, $body, $fromEmail, $fromName, $cred, $path);
            }
        }

        // Fallback: natywna mail()
        return $this->sendViaMailFn($recipient, $subject, $htmlBody, $fromEmail, $fromName);
    }

    private function sendViaPHPMailer(
        string $recipient, string $subject, string $htmlBody, string $textBody,
        string $fromEmail, string $fromName, array $cred, string $autoloadPath
    ): DeliveryResult {
        try {
            require_once $autoloadPath;

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = (string)($cred['host'] ?? 'localhost');
            $mail->Port       = (int)($cred['port'] ?? 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = (string)($cred['username'] ?? $fromEmail);
            $mail->Password   = (string)($cred['password'] ?? '');

            $enc = strtolower((string)($cred['encryption'] ?? 'tls'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject ?: 'Powiadomienie z restauracji';
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();
            return DeliveryResult::ok();
        } catch (\Throwable $e) {
            return DeliveryResult::fail('PHPMailer: ' . $e->getMessage());
        }
    }

    private function sendViaMailFn(
        string $recipient, string $subject, string $htmlBody,
        string $fromEmail, string $fromName
    ): DeliveryResult {
        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ($fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail),
            'X-Mailer: SliceHub-NotificationDispatcher',
        ]);

        $sent = @mail($recipient, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $htmlBody, $headers);

        if ($sent) {
            return DeliveryResult::ok();
        }
        return DeliveryResult::fail('mail() returned false. Check PHP mail configuration.');
    }
}
