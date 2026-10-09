<?php
declare(strict_types=1);

// Завантажує свіжі дані з Contentful і показує, що бачить бот, зокрема чи є WAV на сервері:
// php bin/check-tracks.php

use App\Catalog;

$app = require dirname(__DIR__) . '/bootstrap.php';
/** @var Catalog $catalog */
$catalog = $app['catalog'];

try {
    $catalog->refresh();
} catch (\Throwable $e) {
    fwrite(STDERR, '✗ ' . $e->getMessage() . "\n");
    exit(1);
}

$mb = static fn (int $bytes) => sprintf('%.1f МБ', $bytes / 1024 / 1024);
$limit = 50 * 1024 * 1024;

$settings = $catalog->settings();
echo "Налаштування:\n";
echo '  Ціни за замовчуванням: ' . Catalog::formatPrice($settings['prices'][Catalog::UA], 'UAH')
    . ' / ' . Catalog::formatPrice($settings['prices'][Catalog::INT], 'USD') . "\n";
echo "  Підтримка: {$settings['support_contact']}\n";
echo '  Папка WAV: ' . $app['config']['wav_dir'] . (is_dir($app['config']['wav_dir']) ? '' : '  ✗ НЕ ІСНУЄ') . "\n";
echo '  Посилання для великих WAV: ' . ($app['config']['app_url'] ?: '✗ APP_URL не задано') . "\n\n";

$tracks = $app['content']->tracks();
echo 'Фонограми (' . count($tracks) . "):\n";
$errors = 0;
foreach ($tracks as $track) {
    $ua = $catalog->price($track, Catalog::UA);
    $int = $catalog->price($track, Catalog::INT);
    $mp3TooBig = $track['file']['size'] > $limit;
    $errors += (int) $mp3TooBig;

    printf(
        "  %s%s — %s / %s\n",
        $track['title'],
        $track['hidden'] ? ' [знято з продажу]' : '',
        Catalog::formatPrice($ua['amount'], $ua['currency']),
        Catalog::formatPrice($int['amount'], $int['currency']),
    );
    printf(
        "    %s MP3: %s (%s)%s\n",
        $mp3TooBig ? '✗' : '✓',
        $track['file']['name'],
        $mb($track['file']['size']),
        $mp3TooBig ? ' — БІЛЬШЕ 50 МБ, Telegram не прийме' : '',
    );

    if ($wav = $catalog->wavFile($track)) {
        printf(
            "    ✓ WAV: %s (%s, %s)\n",
            $wav['name'],
            $mb($wav['size']),
            $wav['size'] > $limit ? 'посиланням' : 'файлом у Telegram',
        );
    } else {
        $errors++;
        echo '    ✗ WAV: не знайдено, очікується ' . implode(' або ', $catalog->wavCandidates($track)) . "\n";
    }
}

echo $errors ? "\nПроблем: $errors\n" : "\nУсе гаразд.\n";
exit($errors ? 1 : 0);
