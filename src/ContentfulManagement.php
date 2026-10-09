<?php
declare(strict_types=1);

namespace App;

/**
 * Мінімальний клієнт Contentful Management API для службових скриптів у bin/.
 * Сам бот ним не користується — йому достатньо Delivery API.
 */
final class ContentfulManagement
{
    private ?string $defaultLocale = null;

    public function __construct(
        private readonly string $spaceId,
        private readonly string $token,
        private readonly string $environment = 'master',
    ) {
    }

    /**
     * Запит до /spaces/{space}/environments/{env}{$path}.
     *
     * @return array{0: int, 1: array} [HTTP-код, відповідь]
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $url = sprintf(
            'https://api.contentful.com/spaces/%s/environments/%s%s',
            rawurlencode($this->spaceId),
            rawurlencode($this->environment),
            $path,
        );
        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;

        return $this->send($method, $url, $payload, ['Content-Type' => 'application/vnd.contentful.management.v1+json'] + $headers);
    }

    /**
     * Те саме, що request(), але при помилці зупиняє скрипт із поясненням.
     */
    public function ok(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        [$code, $data] = $this->request($method, $path, $body, $headers);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("$method $path: HTTP $code " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        return $data;
    }

    /**
     * Завантажує файл у Contentful і повертає ID завантаження для створення asset.
     */
    public function upload(string $filePath): string
    {
        $url = sprintf('https://upload.contentful.com/spaces/%s/uploads', rawurlencode($this->spaceId));
        [$code, $data] = $this->send('POST', $url, (string) file_get_contents($filePath), ['Content-Type' => 'application/octet-stream']);
        if ($code !== 201) {
            throw new \RuntimeException("Завантаження файлу: HTTP $code " . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        return $data['sys']['id'];
    }

    public function defaultLocale(): string
    {
        if ($this->defaultLocale === null) {
            $this->defaultLocale = 'en-US';
            foreach ($this->ok('GET', '/locales')['items'] as $locale) {
                if (!empty($locale['default'])) {
                    $this->defaultLocale = $locale['code'];
                }
            }
        }
        return $this->defaultLocale;
    }

    /**
     * Публікує запис або asset ($type = 'entries' | 'assets').
     */
    public function publish(string $type, array $item): array
    {
        return $this->ok('PUT', "/$type/{$item['sys']['id']}/published", null, [
            'X-Contentful-Version' => $item['sys']['version'],
        ]);
    }

    /**
     * Знімає з публікації (якщо треба) і видаляє. Повертає false, якщо такого вже немає.
     */
    public function remove(string $type, string $id): bool
    {
        [$code, $item] = $this->request('GET', "/$type/$id");
        if ($code === 404) {
            return false;
        }
        if (isset($item['sys']['publishedVersion'])) {
            $item = $this->ok('DELETE', "/$type/$id/published", null, ['X-Contentful-Version' => $item['sys']['version']]);
        }
        $this->ok('DELETE', "/$type/$id", null, ['X-Contentful-Version' => $item['sys']['version']]);
        return true;
    }

    private function send(string $method, string $url, ?string $payload, array $headers): array
    {
        $httpHeaders = ['Authorization: Bearer ' . $this->token];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = "$name: $value";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_TIMEOUT => 120,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('Contentful: ' . curl_error($ch));
        }

        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($raw, true) ?? []];
    }
}
