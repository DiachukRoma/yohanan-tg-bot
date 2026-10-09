<?php
declare(strict_types=1);

// Завантаження WAV за підписаним посиланням з бота (для файлів, більших за ліміт Telegram у 50 МБ).
// Підтримує докачування (Range), щоб великий файл можна було дозавантажити після обриву зв'язку.

use App\DownloadLink;
use App\Log;

$app = require dirname(__DIR__) . '/bootstrap.php';

function fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;line-height:1.5">'
        . '<p>' . htmlspecialchars($message) . '</p></body>';
    exit;
}

$link = new DownloadLink($app['config']['app_url'], $app['config']['download_secret']);
$data = $link->verify((string) ($_GET['t'] ?? ''));
if ($data === null) {
    fail(403, 'Посилання недійсне або його строк минув. Отримайте нове в боті: «🧾 Мої покупки».');
}

$track = $app['catalog']->get($data['t']);
if ($track === null || !$app['storage']->hasPurchased((int) $data['u'], $data['t'])) {
    fail(404, 'Фонограму не знайдено.');
}

$file = $data['f'] === 'wav' ? $app['catalog']->wavFile($track) : null;
if ($file === null) {
    Log::error("download.php: немає WAV для фонограми {$data['t']}");
    fail(404, 'Файл тимчасово недоступний. Напишіть, будь ласка, ' . $app['catalog']->settings()['support_contact'] . '.');
}

$size = $file['size'];
$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int) $m[2]);
    } elseif ($m[1] !== '') {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min((int) $m[2], $size - 1);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

// Справжнє (кириличне) ім'я — у filename*; filename лише для дуже старих браузерів
$asciiName = preg_match('/^[\x20-\x7E]+$/', $file['name']) ? str_replace('"', '', $file['name']) : 'fonogram.wav';
header('Content-Type: audio/wav');
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header("Content-Disposition: attachment; filename=\"$asciiName\"; filename*=UTF-8''" . rawurlencode($file['name']));
header('Cache-Control: private, no-store');
header('X-Accel-Buffering: no');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fh = fopen($file['path'], 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, min(1024 * 1024, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    flush();
    $left -= strlen($chunk);
}
fclose($fh);
