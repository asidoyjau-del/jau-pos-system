<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Brevo transactional email (same provider/env vars as the POS). Transport is injectable for tests. */
final class Mailer
{
    /** @var (callable(string,string,string,string):array{0:bool,1:string})|null */
    private static $transport = null;

    public static function setTransport(?callable $t): void
    {
        self::$transport = $t;
    }

    /** @return array{0:bool,1:string} [ok, message] */
    public static function send(string $toEmail, string $toName, string $subject, string $html): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($toEmail, $toName, $subject, $html);
        }
        $key = trim((string)Env::get('BREVO_API_KEY', ''));
        $from = trim((string)Env::get('BREVO_SENDER_EMAIL', ''));
        if ($key === '' || $from === '') {
            return [false, 'Email provider is not configured (BREVO_API_KEY / BREVO_SENDER_EMAIL).'];
        }
        $payload = json_encode([
            'sender'      => ['name' => (string)Env::get('BREVO_SENDER_NAME', 'ProCast'), 'email' => $from],
            'to'          => [['email' => $toEmail, 'name' => $toName]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ]);
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return [true, 'sent'];
        }
        return [false, $err !== '' ? $err : ('Brevo HTTP ' . $code . ': ' . substr((string)$resp, 0, 200))];
    }
}
