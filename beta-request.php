<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, bool $ok, string $message = ''): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Diese Anfrage ist nicht erlaubt.');
}

$honeypot = $_POST['website'] ?? '';
if (!is_string($honeypot) || trim($honeypot) !== '') {
    respond(200, true);
}

$email = $_POST['email'] ?? '';
if (!is_string($email)) {
    respond(422, false, 'Bitte gib eine gültige E-Mail-Adresse ein.');
}
$email = trim($email);
if (strlen($email) > 180 || strpbrk($email, "\r\n") !== false || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'Bitte gib eine gültige E-Mail-Adresse ein.');
}

// Use the server-provided address, never a client-supplied forwarding header.
$rateDirectory = sys_get_temp_dir() . '/trittwerk-beta-' . hash('sha256', __DIR__);
if (!is_dir($rateDirectory) && !@mkdir($rateDirectory, 0700, true) && !is_dir($rateDirectory)) {
    respond(503, false, 'Der Versand ist gerade nicht verfügbar. Bitte versuche es später erneut.');
}
foreach (glob($rateDirectory . '/*.lock') ?: [] as $oldFile) {
    if (@filemtime($oldFile) < time() - 3600) {
        @unlink($oldFile);
    }
}
$rateFile = $rateDirectory . '/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.lock';
$lock = @fopen($rateFile, 'c+');
if ($lock === false) {
    respond(503, false, 'Der Versand ist gerade nicht verfügbar. Bitte versuche es später erneut.');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    header('Retry-After: 60');
    respond(429, false, 'Bitte warte eine Minute, bevor du erneut einen Link anforderst.');
}
$lastSent = (int) stream_get_contents($lock);
if (time() - $lastSent < 60) {
    flock($lock, LOCK_UN);
    fclose($lock);
    header('Retry-After: 60');
    respond(429, false, 'Bitte warte eine Minute, bevor du erneut einen Link anforderst.');
}

$betaLink = 'https://testflight.apple.com/join/uVk4wdaM';
$subject = 'Dein Trittwerk Beta-Link';
$body = "Hier ist dein Link zur Trittwerk-Beta:\n\n{$betaLink}\n";
$headers = [
    'From: Trittwerk <support@trittwerk.eu>',
    'Reply-To: support@trittwerk.eu',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = mail($email, $subject, $body, implode("\r\n", $headers));
if ($sent) {
    rewind($lock);
    ftruncate($lock, 0);
    fwrite($lock, (string) time());
    fflush($lock);
}
flock($lock, LOCK_UN);
fclose($lock);

if (!$sent) {
    respond(500, false, 'Die E-Mail konnte gerade nicht gesendet werden. Bitte versuche es später erneut.');
}

// A failed owner notification must not turn a successful beta request into an error.
$notificationBody = implode("\n", [
    'Eine neue Person hat den Trittwerk Beta-Link angefordert.',
    '',
    'E-Mail: ' . $email,
    'Zeit (UTC): ' . gmdate('Y-m-d H:i:s'),
    '',
    'Die E-Mail mit dem TestFlight-Link wurde zum Versand übergeben.',
    'Dies bestätigt weder die Zustellung noch eine Installation der App.',
]);
$notificationSent = mail(
    'support@trittwerk.eu',
    'Trittwerk: Neue Beta-Link-Anfrage',
    $notificationBody,
    implode("\r\n", $headers)
);
if (!$notificationSent) {
    error_log('Trittwerk: Beta notification could not be sent.');
}
respond(200, true);
