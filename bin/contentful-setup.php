<?php
declare(strict_types=1);

// Створює в Contentful типи контенту «Фонограма» і «Налаштування бота»
// та чернетку налаштувань. Повторний запуск безпечний: існуючі типи оновлюються, дані не чіпаються.
//
// Потрібні в .env: CONTENTFUL_SPACE_ID, CONTENTFUL_ENVIRONMENT, CONTENTFUL_MANAGEMENT_TOKEN.
// Використання: php bin/contentful-setup.php

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

$maxFileSize = 50 * 1024 * 1024;

$types = [
    Contentful::TRACK_TYPE => [
        'name' => 'Фонограма',
        'description' => 'Фонограма для продажу в Telegram-боті',
        'displayField' => 'title',
        'fields' => [
            ['id' => 'title', 'name' => 'Назва', 'type' => 'Symbol', 'required' => true],
            [
                'id' => 'file', 'name' => 'MP3-файл', 'type' => 'Link', 'linkType' => 'Asset', 'required' => true,
                'validations' => [
                    ['linkMimetypeGroup' => ['audio', 'archive']],
                    ['assetFileSize' => ['max' => $maxFileSize], 'message' => 'Telegram приймає файли до 50 МБ'],
                ],
            ],
            ['id' => 'wavFile', 'name' => 'Ім\'я WAV-файлу на сервері', 'type' => 'Symbol'],
            ['id' => 'description', 'name' => 'Опис', 'type' => 'Text'],
            ['id' => 'priceUa', 'name' => 'Ціна для України, грн', 'type' => 'Number', 'validations' => [['range' => ['min' => 0]]]],
            ['id' => 'priceUsd', 'name' => 'Ціна для інших країн, $', 'type' => 'Number', 'validations' => [['range' => ['min' => 0]]]],
            ['id' => 'order', 'name' => 'Порядок у каталозі', 'type' => 'Integer'],
            ['id' => 'hidden', 'name' => 'Зняти з продажу', 'type' => 'Boolean'],
        ],
        'help' => [
            'file' => 'MP3 до 50 МБ. Покупець отримає файл саме з таким ім\'ям. Не забудьте опублікувати файл. WAV кладіть на сервер у папку wav/ з тією ж назвою, що й MP3.',
            'wavFile' => 'Зазвичай лишайте порожнім: бот сам шукає в папці wav/ файл з такою ж назвою, як MP3 (або як назва фонограми), але з розширенням .wav. Заповніть, лише якщо ім\'я WAV інше.',
            'description' => 'Необов\'язково: тональність, темп, з бек-вокалом чи без.',
            'priceUa' => 'Порожньо — ціна за замовчуванням із «Налаштувань бота».',
            'priceUsd' => 'Порожньо — ціна за замовчуванням із «Налаштувань бота».',
            'order' => 'Менше число — вище в каталозі. Порожньо — у кінці, за назвою.',
            'hidden' => 'Щоб прибрати фонограму з каталогу, увімкніть це, а не знімайте з публікації: ті, хто вже купив, зможуть завантажити її повторно.',
        ],
    ],
    Contentful::SETTINGS_TYPE => [
        'name' => 'Налаштування бота',
        'description' => 'Ціни, реквізити й тексти бота. Має бути лише один такий запис.',
        'displayField' => 'title',
        'fields' => [
            ['id' => 'title', 'name' => 'Назва запису', 'type' => 'Symbol', 'required' => true],
            ['id' => 'priceUa', 'name' => 'Ціна для України, грн', 'type' => 'Number', 'required' => true, 'validations' => [['range' => ['min' => 0]]]],
            ['id' => 'priceUsd', 'name' => 'Ціна для інших країн, $', 'type' => 'Number', 'required' => true, 'validations' => [['range' => ['min' => 0]]]],
            ['id' => 'paymentUa', 'name' => 'Реквізити для оплати з України', 'type' => 'Text', 'required' => true],
            ['id' => 'paymentInt', 'name' => 'Реквізити для оплати з-за кордону', 'type' => 'Text', 'required' => true],
            ['id' => 'supportContact', 'name' => 'Контакт підтримки', 'type' => 'Symbol', 'required' => true],
            ['id' => 'welcomeText', 'name' => 'Привітання', 'type' => 'Text'],
        ],
        'help' => [
            'priceUa' => 'Ціна за замовчуванням. Для окремої фонограми можна вказати свою.',
            'priceUsd' => 'Ціна за замовчуванням. Для окремої фонограми можна вказати свою.',
            'paymentUa' => 'Можна <b>жирний</b> і <code>4441 1111 2222 3333</code> (тап по <code> копіює текст).',
            'paymentInt' => 'PayPal, IBAN, картка тощо. Розмітка як у реквізитах для України.',
            'supportContact' => 'Наприклад, @username — куди писати покупцю, якщо щось пішло не так.',
            'welcomeText' => 'Необов\'язково. Перше повідомлення бота.',
        ],
    ],
];

foreach ($types as $id => $def) {
    $help = $def['help'];
    unset($def['help']);

    [$code, $existing] = $cma->request('GET', "/content_types/$id");
    $headers = [];
    if ($code === 200) {
        $headers['X-Contentful-Version'] = $existing['sys']['version'];
        // Поля, додані вручну, не видаляємо
        $ours = array_column($def['fields'], 'id');
        foreach ($existing['fields'] as $field) {
            if (!in_array($field['id'], $ours, true)) {
                $def['fields'][] = $field;
            }
        }
    }

    $ct = $cma->ok('PUT', "/content_types/$id", $def, $headers);
    $cma->ok('PUT', "/content_types/$id/published", null, ['X-Contentful-Version' => $ct['sys']['version']]);

    // Підказки під полями в редакторі. Editor interface з'являється не миттєво після публікації.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        [$code, $ei] = $cma->request('GET', "/content_types/$id/editor_interface");
        if ($code === 200) {
            break;
        }
        sleep(1);
    }
    if ($code === 200) {
        foreach ($ei['controls'] as &$control) {
            if (isset($help[$control['fieldId']])) {
                $control['settings'] = ['helpText' => $help[$control['fieldId']]] + ($control['settings'] ?? []);
            }
        }
        unset($control);
        $cma->ok('PUT', "/content_types/$id/editor_interface", ['controls' => $ei['controls']], [
            'X-Contentful-Version' => $ei['sys']['version'],
        ]);
    } else {
        echo "! Не вдалося додати підказки до полів типу $id (це не критично)\n";
    }

    echo "✓ Тип контенту «{$def['name']}» ($id)\n";
}

// Чернетка налаштувань, якщо ще немає жодної
$found = $cma->ok('GET', '/entries?content_type=' . Contentful::SETTINGS_TYPE . '&limit=1');
if ($found['total'] > 0) {
    echo "✓ Запис «Налаштування бота» вже існує — не змінюю\n";
} else {
    $locale = $cma->defaultLocale();

    $fields = [
        'title' => 'Налаштування бота',
        'priceUa' => 200,
        'priceUsd' => 20,
        'paymentUa' => "💳 Картка: <code>0000 0000 0000 0000</code>\n👤 Отримувач: Ім'я Прізвище",
        'paymentInt' => "💳 PayPal: <code>name@example.com</code>",
        'supportContact' => '@username',
    ];
    $cma->ok('POST', '/entries', ['fields' => array_map(static fn ($v) => [$locale => $v], $fields)], [
        'X-Contentful-Content-Type' => Contentful::SETTINGS_TYPE,
    ]);
    echo "✓ Створено чернетку «Налаштування бота». Впишіть справжні реквізити й натисніть Publish.\n";
}

echo "\nГотово. Додавайте фонограми (Content → Add entry → Фонограма) і публікуйте їх.\n";
