<?php
// Значення беруться з .env (див. .env.example). Тут їх не редагуйте.
// Фонограми, ціни, реквізити та тексти редагуються в Contentful.

use App\Env;

// Відносні шляхи в .env рахуються від кореня проєкту
$path = static fn (string $p): string => str_starts_with($p, '/') ? $p : __DIR__ . '/' . $p;

$adminIds = array_values(array_filter(array_map('intval', explode(',', Env::required('ADMIN_IDS')))));
if (!$adminIds) {
    throw new RuntimeException('ADMIN_IDS у .env має містити хоча б один числовий Telegram ID');
}

return [
    'bot_token' => Env::required('BOT_TOKEN'),
    'webhook_secret' => Env::required('WEBHOOK_SECRET'),
    'admin_ids' => $adminIds,
    'timezone' => Env::get('TIMEZONE', 'Europe/Kyiv'),
    'contentful' => [
        'space_id' => Env::required('CONTENTFUL_SPACE_ID'),
        'access_token' => Env::get('CONTENTFUL_DELIVERY_ACCESS_TOKEN') ?: Env::required('CONTENTFUL_ACCESS_TOKEN'),
        'environment' => Env::get('CONTENTFUL_ENVIRONMENT', 'master'),
        'locale' => Env::get('CONTENTFUL_LOCALE') ?: null,
        'cache_ttl' => (int) Env::get('CONTENTFUL_CACHE_TTL', '300'),
        'webhook_secret' => Env::get('CONTENTFUL_WEBHOOK_SECRET', ''),
    ],
    // Адреса бота в інтернеті — для посилань на завантаження WAV, більших за 50 МБ
    'app_url' => rtrim((string) Env::get('APP_URL', ''), '/'),
    // Ключ для підпису посилань на завантаження. Якщо не задано, виводиться з токена бота.
    'download_secret' => Env::get('DOWNLOAD_SECRET') ?: hash('sha256', 'download|' . Env::required('BOT_TOKEN')),
    'wav_dir' => $path(Env::get('WAV_DIR', 'wav')),
    'cache_path' => $path(Env::get('CACHE_PATH', 'storage/cache/contentful.json')),
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::required('DB_NAME'),
        'user' => Env::required('DB_USER'),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'log_path' => $path(Env::get('LOG_PATH', 'storage/logs/bot.log')),
];
