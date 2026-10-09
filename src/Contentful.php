<?php
declare(strict_types=1);

namespace App;

/**
 * Читання фонограм і налаштувань бота з Contentful Delivery API.
 * Структуру типів контенту створює bin/contentful-setup.php.
 */
class Contentful
{
    public const TRACK_TYPE = 'track';
    public const SETTINGS_TYPE = 'botSettings';

    private const PAGE_SIZE = 200;
    private const REQUIRED_SETTINGS = ['priceUa', 'priceUsd', 'paymentUa', 'paymentInt', 'supportContact'];

    public function __construct(
        private readonly string $spaceId,
        private readonly string $accessToken,
        private readonly string $environment = 'master',
        private readonly ?string $locale = null,
    ) {
    }

    /**
     * @return array<string, array> фонограми за ID запису, у порядку каталогу
     */
    public function fetchTracks(): array
    {
        $tracks = [];
        $skip = 0;
        do {
            $page = $this->get('entries', [
                'content_type' => self::TRACK_TYPE,
                'include' => 1,
                'limit' => self::PAGE_SIZE,
                'skip' => $skip,
            ]);

            $assets = [];
            foreach ($page['includes']['Asset'] ?? [] as $asset) {
                $assets[$asset['sys']['id']] = $asset;
            }
            foreach ($page['items'] as $item) {
                if ($track = $this->mapTrack($item, $assets)) {
                    $tracks[$track['id']] = $track;
                }
            }

            $skip += self::PAGE_SIZE;
        } while ($skip < $page['total']);

        uasort($tracks, static fn (array $a, array $b) =>
            [$a['order'] ?? PHP_INT_MAX, $a['title']] <=> [$b['order'] ?? PHP_INT_MAX, $b['title']]);

        return $tracks;
    }

    /**
     * @return array{prices: array, payment: array, support_contact: string, welcome_text: string}
     */
    public function fetchSettings(): array
    {
        $page = $this->get('entries', ['content_type' => self::SETTINGS_TYPE, 'limit' => 1]);
        $f = $page['items'][0]['fields']
            ?? throw new \RuntimeException('У Contentful немає опублікованого запису «Налаштування бота»');

        foreach (self::REQUIRED_SETTINGS as $field) {
            if (!isset($f[$field]) || $f[$field] === '') {
                throw new \RuntimeException("Contentful: у «Налаштуваннях бота» не заповнено поле $field");
            }
        }

        return [
            'prices' => [Catalog::UA => (float) $f['priceUa'], Catalog::INT => (float) $f['priceUsd']],
            'payment' => [Catalog::UA => (string) $f['paymentUa'], Catalog::INT => (string) $f['paymentInt']],
            'support_contact' => (string) $f['supportContact'],
            'welcome_text' => trim((string) ($f['welcomeText'] ?? '')),
        ];
    }

    private function mapTrack(array $item, array $assets): ?array
    {
        $id = (string) $item['sys']['id'];
        $f = $item['fields'];
        $asset = $assets[$f['file']['sys']['id'] ?? ''] ?? null;
        $file = $asset['fields']['file'] ?? null;

        if (empty($f['title']) || empty($file['url'])) {
            // Найчастіше — файл завантажено, але не опубліковано
            Log::error("Contentful: фонограму $id пропущено — немає назви або опублікованого файлу");
            return null;
        }

        $url = str_starts_with($file['url'], '//') ? 'https:' . $file['url'] : $file['url'];

        return [
            'id' => $id,
            'title' => (string) $f['title'],
            'description' => $f['description'] ?? null,
            'price' => array_filter(
                [Catalog::UA => $f['priceUa'] ?? null, Catalog::INT => $f['priceUsd'] ?? null],
                static fn ($v) => $v !== null,
            ),
            'hidden' => !empty($f['hidden']),
            // Ім'я WAV-файлу на сервері, якщо воно відрізняється від імені MP3
            'wav_name' => trim((string) ($f['wavFile'] ?? '')) ?: null,
            'order' => isset($f['order']) ? (int) $f['order'] : null,
            'file' => [
                'url' => $url,
                'name' => $file['fileName'] ?? basename((string) parse_url($url, PHP_URL_PATH)),
                'mime' => $file['contentType'] ?? 'application/octet-stream',
                'size' => (int) ($file['details']['size'] ?? 0),
                // Змінюється при заміні файлу — за цим ключем кешуємо file_id у Telegram
                'version' => $asset['sys']['id'] . '@' . ($asset['sys']['revision'] ?? $asset['sys']['updatedAt'] ?? ''),
            ],
        ];
    }

    protected function get(string $path, array $query = []): array
    {
        if ($this->locale) {
            $query['locale'] = $this->locale;
        }
        $url = sprintf(
            'https://cdn.contentful.com/spaces/%s/environments/%s/%s?%s',
            rawurlencode($this->spaceId),
            rawurlencode($this->environment),
            $path,
            http_build_query($query),
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('Contentful: ' . curl_error($ch));
        }

        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = json_decode($raw, true);
        if ($code !== 200 || !is_array($data)) {
            throw new \RuntimeException("Contentful HTTP $code: " . ($data['message'] ?? mb_substr($raw, 0, 200)));
        }

        return $data;
    }
}
