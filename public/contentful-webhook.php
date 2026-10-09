<?php
declare(strict_types=1);

// Contentful викликає цей URL після публікації змін — бот скидає кеш і одразу бачить нові дані.
// Налаштування: Contentful → Settings → Webhooks → Add webhook
//   URL: https://your-domain.com/contentful-webhook.php, метод POST
//   Triggers: Entry і Asset — Publish, Unpublish, Delete
//   Headers: X-Webhook-Secret = значення CONTENTFUL_WEBHOOK_SECRET з .env

$app = require dirname(__DIR__) . '/bootstrap.php';

$secret = $app['config']['contentful']['webhook_secret'];
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || $secret === ''
    || !hash_equals($secret, $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '')
) {
    http_response_code(403);
    exit;
}

$app['content']->invalidate();
echo 'ok';
