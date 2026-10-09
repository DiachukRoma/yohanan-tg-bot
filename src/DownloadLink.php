<?php
declare(strict_types=1);

namespace App;

/**
 * Підписані посилання на завантаження WAV, більших за ліміт Telegram у 50 МБ.
 * Посилання прив'язане до покупця й фонограми та діє обмежений час;
 * public/download.php додатково перевіряє, що покупка досі підтверджена.
 */
final class DownloadLink
{
    public const TTL = 24 * 3600;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $secret,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->baseUrl !== '';
    }

    public function create(int $userId, string $trackId, string $format): string
    {
        $payload = self::base64url(json_encode(['u' => $userId, 't' => $trackId, 'f' => $format, 'e' => time() + self::TTL]));

        return $this->baseUrl . '/download.php?t=' . $payload . '.' . $this->sign($payload);
    }

    /**
     * @return array{u: int, t: string, f: string, e: int}|null null, якщо підпис неправильний або строк минув
     */
    public function verify(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || !hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (!isset($data['u'], $data['t'], $data['f'], $data['e']) || $data['e'] < time()) {
            return null;
        }
        return $data;
    }

    private function sign(string $payload): string
    {
        return self::base64url(substr(hash_hmac('sha256', $payload, $this->secret, true), 0, 18));
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
