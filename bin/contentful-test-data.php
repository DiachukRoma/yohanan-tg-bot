<?php
declare(strict_types=1);

// Тестові фонограми для розробки: генерує короткі мелодії, завантажує їх у Contentful і публікує.
//
//   php bin/contentful-test-data.php           — створити (повторний запуск пропускає вже створені)
//   php bin/contentful-test-data.php --delete  — видалити всі тестові фонограми та їхні файли
//
// Записи мають ID test-track-N, а файли test-audio-N, тож справжній контент скрипт не зачіпає.
// Потрібен CONTENTFUL_MANAGEMENT_TOKEN у .env.

use App\Contentful;
use App\ContentfulManagement;
use App\Env;

$root = dirname(__DIR__);
require "$root/src/Env.php";
require "$root/src/Contentful.php";
require "$root/src/ContentfulManagement.php";
Env::load("$root/.env");

$cma = new ContentfulManagement(
    Env::required('CONTENTFUL_SPACE_ID'),
    Env::required('CONTENTFUL_MANAGEMENT_TOKEN'),
    Env::get('CONTENTFUL_ENVIRONMENT', 'master'),
);

// Ноти (Гц) для мелодій
const C4 = 261.63, D4 = 293.66, E4 = 329.63, F4 = 349.23, G4 = 392.00, A4 = 440.00, B4 = 493.88;
const C5 = 523.25, D5 = 587.33, E5 = 659.25, R = 0.0;

// title, мелодія, темп (BPM), формат, додаткові поля
$tracks = [
    1 => ['Ой у лузі червона калина', [E4, E4, A4, A4, B4, C5, B4, A4, G4, A4, B4, E4], 110, 'm4a', ['description' => 'Тональність: Am, темп 110']],
    2 => ['Ніч яка місячна', [C4, E4, G4, C5, G4, E4, F4, A4, C5, A4, G4, R], 90, 'wav', ['description' => 'З бек-вокалом', 'priceUa' => 250, 'priceUsd' => 25]],
    3 => ['Червона рута', [G4, A4, B4, D5, B4, A4, G4, E4, D4, E4, G4, R], 128, 'm4a', []],
    4 => ['Два кольори', [D4, F4, A4, D5, C5, A4, F4, G4, A4, F4, D4, R], 76, 'm4a', ['description' => 'Тональність: Dm']],
    5 => ['Смерековая хата', [C4, D4, E4, G4, E4, D4, C4, G4, A4, G4, E4, C4], 100, 'wav', []],
    6 => ['Цвіте терен', [A4, C5, E5, C5, A4, E4, F4, A4, C5, B4, A4, R], 84, 'm4a', []],
    7 => ['Місяць на небі', [E4, G4, B4, E5, D5, B4, G4, A4, B4, G4, E4, R], 96, 'm4a', []],
    8 => ['Пісня про рушник', [F4, A4, C5, F4, G4, B4, D5, G4, A4, C5, F4, R], 72, 'm4a', ['priceUa' => 150, 'priceUsd' => 15]],
    9 => ['Несе Галя воду', [G4, G4, E4, C4, D4, E4, F4, D4, E4, C4, G4, R], 120, 'm4a', []],
    10 => ['Знята з продажу (не має бути в каталозі)', [C5, B4, A4, G4, F4, E4, D4, C4], 100, 'm4a', ['hidden' => true]],
];

if (in_array('--delete', $argv, true)) {
    foreach (array_keys($tracks) as $n) {
        $entry = $cma->remove('entries', "test-track-$n");
        $asset = $cma->remove('assets', "test-audio-$n");
        echo ($entry || $asset ? '✓ Видалено' : '· Немає') . " тестову фонограму #$n\n";
    }
    echo "\nГотово. Щоб бот одразу це побачив, надішліть йому /refresh.\n";
    exit;
}

$locale = $cma->defaultLocale();
$tmpDir = sys_get_temp_dir() . '/yohanan-test-audio-' . getmypid();
mkdir($tmpDir);

try {
    foreach ($tracks as $n => [$title, $melody, $bpm, $format, $extra]) {
        $title = "[ТЕСТ] $title";
        $entryId = "test-track-$n";
        $assetId = "test-audio-$n";

        [$code] = $cma->request('GET', "/entries/$entryId");
        if ($code === 200) {
            echo "· #$n вже існує — пропускаю\n";
            continue;
        }

        // 1. Генеруємо аудіо
        $wav = "$tmpDir/$n.wav";
        file_put_contents($wav, renderWav($melody, $bpm));
        $file = $wav;
        $mime = 'audio/wav';
        if ($format === 'm4a' && is_executable('/usr/bin/afconvert')) {
            $m4a = "$tmpDir/$n.m4a";
            exec(sprintf('/usr/bin/afconvert -f m4af -d aac -b 64000 %s %s 2>&1', escapeshellarg($wav), escapeshellarg($m4a)), $out, $rc);
            if ($rc === 0) {
                [$file, $mime] = [$m4a, 'audio/mp4'];
            }
        }
        $fileName = sprintf('Test %02d - %s.%s', $n, transliterate(substr($title, strlen('[ТЕСТ] '))), pathinfo($file, PATHINFO_EXTENSION));

        // 2. Asset: завантаження → обробка → публікація
        $uploadId = $cma->upload($file);
        [$code, $asset] = $cma->request('GET', "/assets/$assetId");
        $asset = $cma->ok('PUT', "/assets/$assetId", ['fields' => [
            'title' => [$locale => $title],
            'file' => [$locale => [
                'contentType' => $mime,
                'fileName' => $fileName,
                'uploadFrom' => ['sys' => ['type' => 'Link', 'linkType' => 'Upload', 'id' => $uploadId]],
            ]],
        ]], $code === 200 ? ['X-Contentful-Version' => $asset['sys']['version']] : []);

        $cma->ok('PUT', "/assets/$assetId/files/$locale/process", null, ['X-Contentful-Version' => $asset['sys']['version']]);
        $asset = waitForProcessing($cma, $assetId, $locale);
        $cma->publish('assets', $asset);

        // 3. Запис фонограми
        $fields = ['title' => $title, 'order' => $n] + $extra;
        $fields = array_map(static fn ($v) => [$locale => $v], $fields);
        $fields['file'] = [$locale => ['sys' => ['type' => 'Link', 'linkType' => 'Asset', 'id' => $assetId]]];

        $entry = $cma->ok('PUT', "/entries/$entryId", ['fields' => $fields], [
            'X-Contentful-Content-Type' => Contentful::TRACK_TYPE,
        ]);
        $cma->publish('entries', $entry);

        printf("✓ #%d %s (%s, %d КБ)\n", $n, $title, $fileName, filesize($file) / 1024);
    }
} finally {
    array_map('unlink', glob("$tmpDir/*"));
    rmdir($tmpDir);
}

echo "\nГотово. Щоб бот одразу побачив фонограми, надішліть йому /refresh.\n";
echo "Перед запуском для покупців видаліть тестові дані: php bin/contentful-test-data.php --delete\n";

// ---------------------------------------------------------------------------

/**
 * Проста мелодія: кожна нота — четвертна, синус із обертонами та згасанням, плюс тиха квінта для об'єму.
 */
function renderWav(array $melody, int $bpm, int $rate = 22050): string
{
    $beat = 60 / $bpm;
    $samples = '';
    foreach (array_merge($melody, $melody) as $freq) {
        $count = (int) ($beat * $rate);
        for ($i = 0; $i < $count; $i++) {
            $t = $i / $rate;
            $value = 0.0;
            if ($freq > 0) {
                $envelope = min(1, $t * 40) * exp(-3 * $t / $beat);
                $value = $envelope * (
                    0.6 * sin(2 * M_PI * $freq * $t)
                    + 0.2 * sin(4 * M_PI * $freq * $t)
                    + 0.1 * sin(2 * M_PI * $freq * 1.5 * $t)
                );
            }
            $samples .= pack('v', (int) (max(-1, min(1, $value)) * 26000) & 0xFFFF);
        }
    }

    $dataSize = strlen($samples);
    return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', $dataSize) . $samples;
}

function waitForProcessing(ContentfulManagement $cma, string $assetId, string $locale): array
{
    for ($i = 0; $i < 30; $i++) {
        $asset = $cma->ok('GET', "/assets/$assetId");
        if (!empty($asset['fields']['file'][$locale]['url'])) {
            return $asset;
        }
        usleep(500_000);
    }
    throw new RuntimeException("Contentful не обробив файл $assetId за 15 секунд");
}

function transliterate(string $text): string
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh',
        'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ь' => '', 'ю' => 'iu', 'я' => 'ia', "'" => '',
    ];
    $text = strtr(mb_strtolower($text), $map);
    return ucfirst(trim(preg_replace('/[^a-z0-9]+/', ' ', $text)));
}
