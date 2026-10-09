<?php
declare(strict_types=1);

// Локальний запуск без публічного HTTPS: php bin/poll.php
// Якщо встановлено webhook (бот працює на сервері), скрипт відмовиться запускатися без --force.

use App\Log;

$app = require dirname(__DIR__) . '/bootstrap.php';
$tg = $app['tg'];

$webhook = $tg->call('getWebhookInfo')['url'] ?? '';
if ($webhook !== '' && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Бот уже працює через webhook: $webhook\n"
        . "Polling вимкне його, і бот на сервері перестане отримувати повідомлення.\n"
        . "Якщо це справді потрібно: php bin/poll.php --force (потім знову bin/set-webhook.php на сервері).\n");
    exit(1);
}

$tg->call('deleteWebhook');
echo "Бот працює в режимі polling. Ctrl+C — зупинити.\n";

$offset = 0;
while (true) {
    try {
        $updates = $tg->call('getUpdates', [
            'offset' => $offset,
            'timeout' => 30,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    } catch (\Throwable $e) {
        Log::error('getUpdates: ' . $e->getMessage());
        sleep(3);
        continue;
    }

    foreach ($updates as $update) {
        $offset = $update['update_id'] + 1;
        try {
            $app['bot']->handle($update);
        } catch (\Throwable $e) {
            Log::error("Update {$update['update_id']}: $e");
        }
    }
}
