<?php
declare(strict_types=1);

use App\Log;

$app = require dirname(__DIR__) . '/bootstrap.php';

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($app['config']['webhook_secret'], $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);

// Відповідаємо Telegram одразу, щоб він не повторював запит, поки надсилається великий файл
http_response_code(200);
ignore_user_abort(true);
set_time_limit(300);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}

if (is_array($update)) {
    try {
        $app['bot']->handle($update);
    } catch (\Throwable $e) {
        Log::error('Update ' . ($update['update_id'] ?? '?') . ': ' . $e);
    }
}
