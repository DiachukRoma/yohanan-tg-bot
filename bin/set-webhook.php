<?php
declare(strict_types=1);

// Використання: php bin/set-webhook.php https://your-domain.com/webhook.php

$app = require dirname(__DIR__) . '/bootstrap.php';

$url = $argv[1] ?? null;
if ($url === null || !str_starts_with($url, 'https://')) {
    fwrite(STDERR, "Використання: php bin/set-webhook.php https://your-domain.com/webhook.php\n");
    exit(1);
}

$app['tg']->call('setWebhook', [
    'url' => $url,
    'secret_token' => $app['config']['webhook_secret'],
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]);

echo "Webhook встановлено.\n";
print_r($app['tg']->call('getWebhookInfo'));
