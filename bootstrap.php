<?php
declare(strict_types=1);

use App\Bot;
use App\Catalog;
use App\ContentStore;
use App\Contentful;
use App\Env;
use App\Log;
use App\Storage;
use App\Telegram;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

if (!is_file(__DIR__ . '/.env')) {
    throw new RuntimeException('Не знайдено .env — скопіюйте .env.example');
}

Env::load(__DIR__ . '/.env');
$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['timezone']);
Log::init($config['log_path']);

$cf = $config['contentful'];
$content = new ContentStore(
    new Contentful($cf['space_id'], $cf['access_token'], $cf['environment'], $cf['locale']),
    $config['cache_path'],
    $cf['cache_ttl'],
);
$catalog = new Catalog($content, $config['wav_dir']);

$tg = new Telegram($config['bot_token']);
$storage = new Storage($config['db']);
$bot = new Bot($tg, $storage, $catalog, $config);

return [
    'config' => $config,
    'tg' => $tg,
    'bot' => $bot,
    'content' => $content,
    'catalog' => $catalog,
    'storage' => $storage,
];
