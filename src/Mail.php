<?php
declare(strict_types=1);
namespace App;

use PHPMailer\PHPMailer\PHPMailer;

/** Plain-text e-mail through the SMTP in .env. Returns null on success, or the error for the log. */
final class Mail
{
    /** @param array<array{0:string,1:string,2:string}> $attachments [bytes, filename, mime] */
    public static function send(string $to, string $name, string $subject, string $body, array $attachments = []): ?string
    {
        if (env('APP_ENV') === 'staging') { error_log("staging: e-mail não enviado para $to: $subject"); return null; } // ponytail: staging never mails real people
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = (string) env('SMTP_HOST');
            $m->Port = (int) env('SMTP_PORT', '587');
            $m->Timeout = 10; // shared hosting: no queue, fail fast
            if (env('SMTP_USER')) { $m->SMTPAuth = true; $m->Username = (string) env('SMTP_USER'); $m->Password = (string) env('SMTP_PASS'); }
            $m->SMTPSecure = (string) env('SMTP_SECURE', '');
            $m->CharSet = 'UTF-8';
            $m->setFrom((string) env('MAIL_FROM'), (string) (env('MAIL_FROM_NAME') ?: Brand::name()));
            $m->addAddress($to, $name);
            $m->Subject = $subject;
            $m->Body = $body;
            foreach ($attachments as [$bytes, $file, $mime]) $m->addStringAttachment($bytes, $file, PHPMailer::ENCODING_BASE64, $mime);
            $m->send();
            return null;
        } catch (\Throwable $e) {
            error_log('mail: ' . $m->ErrorInfo);
            return $m->ErrorInfo ?: $e->getMessage();
        }
    }
}
