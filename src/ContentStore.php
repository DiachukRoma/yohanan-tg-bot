<?php
declare(strict_types=1);

namespace App;

/**
 * Дані з Contentful із файловим кешем, щоб не ходити в API на кожне повідомлення.
 * Якщо Contentful недоступний, бот працює зі збереженою копією.
 */
final class ContentStore
{
    /** Через скільки секунд повторити запит, якщо Contentful не відповів */
    private const RETRY_AFTER = 60;

    private ?array $data = null;
    private int|false $dataMtime = false;

    public function __construct(
        private readonly Contentful $api,
        private readonly string $cacheFile,
        private readonly int $ttl,
    ) {
    }

    /**
     * @return array<string, array>
     */
    public function tracks(): array
    {
        return $this->load()['tracks'];
    }

    public function settings(): array
    {
        return $this->load()['settings'];
    }

    /**
     * Примусово завантажує свіжі дані. Помилку Contentful прокидає далі.
     */
    public function refresh(): void
    {
        $this->data = null;
        $this->load(true);
    }

    /**
     * Позначає кеш застарілим (викликається з вебхука Contentful після публікації).
     * Самі дані лишаються як запасний варіант.
     */
    public function invalidate(): void
    {
        if ($cached = $this->readCache()) {
            $cached['fetched_at'] = 0;
            $this->writeCache($cached);
        }
        $this->data = null;
    }

    private function load(bool $force = false): array
    {
        // У webhook-режимі процес живе один запит, а в bin/poll.php — годинами,
        // тому дані в пам'яті теж мають строк придатності і скидаються, якщо кеш-файл змінив інший процес
        if (
            !$force
            && $this->data !== null
            && time() - $this->data['fetched_at'] < $this->ttl
            && $this->cacheMtime() === $this->dataMtime
        ) {
            return $this->data;
        }

        $cached = $this->readCache();
        if (!$force && $cached && time() - $cached['fetched_at'] < $this->ttl) {
            return $this->remember($cached);
        }

        try {
            $fresh = [
                'fetched_at' => time(),
                'tracks' => $this->api->fetchTracks(),
                'settings' => $this->api->fetchSettings(),
            ];
        } catch (\Throwable $e) {
            if ($force || !$cached) {
                throw $e;
            }
            Log::error('Contentful недоступний, працюю з кешем: ' . $e->getMessage());
            $cached['fetched_at'] = time() - $this->ttl + self::RETRY_AFTER;
            $this->writeCache($cached);
            return $this->remember($cached);
        }

        $this->writeCache($fresh);
        return $this->remember($fresh);
    }

    private function remember(array $data): array
    {
        $this->dataMtime = $this->cacheMtime();
        return $this->data = $data;
    }

    private function cacheMtime(): int|false
    {
        clearstatcache(true, $this->cacheFile);
        return @filemtime($this->cacheFile);
    }

    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->cacheFile), true);
        return isset($data['fetched_at'], $data['tracks'], $data['settings']) ? $data : null;
    }

    private function writeCache(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Пишемо в тимчасовий файл і перейменовуємо, щоб паралельні запити не прочитали половину
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->cacheFile);
    }
}
