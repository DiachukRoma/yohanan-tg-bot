<?php
declare(strict_types=1);

namespace App;

/**
 * Каталог фонограм і налаштування продажу (дані з Contentful).
 */
final class Catalog
{
    public const UA = 'UA';
    public const INT = 'INT';

    private const CURRENCIES = [self::UA => 'UAH', self::INT => 'USD'];

    /** @var array<string, string>|null нормалізоване ім'я → реальне ім'я файлу в папці WAV */
    private ?array $wavIndex = null;

    public function __construct(
        private readonly ContentStore $content,
        private readonly string $wavDir,
    ) {
    }

    /**
     * Фонограми, які зараз у продажу, у порядку каталогу.
     *
     * @return array<string, array>
     */
    public function forSale(): array
    {
        return array_filter($this->content->tracks(), static fn (array $track) => !$track['hidden']);
    }

    /**
     * Будь-яка опублікована фонограма, зокрема знята з продажу (для повторного завантаження).
     */
    public function get(string $id): ?array
    {
        return $this->content->tracks()[$id] ?? null;
    }

    /**
     * @return array{prices: array, payment: array, support_contact: string, welcome_text: string}
     */
    public function settings(): array
    {
        return $this->content->settings();
    }

    /**
     * @return array{amount: float, currency: string}
     */
    public function price(array $track, string $region): array
    {
        return [
            'amount' => (float) ($track['price'][$region] ?? $this->settings()['prices'][$region]),
            'currency' => self::CURRENCIES[$region],
        ];
    }

    /**
     * WAV-версія фонограми з папки на сервері. Шукається за іменем, указаним у Contentful (поле wavFile),
     * далі — за іменем MP3-файлу з розширенням .wav, далі — за назвою фонограми.
     *
     * @return array{path: string, name: string, size: int, version: string}|null
     */
    public function wavFile(array $track): ?array
    {
        foreach ($this->wavCandidates($track) as $candidate) {
            $name = $this->wavIndex()[self::normalizeName($candidate)] ?? null;
            if ($name !== null) {
                $path = $this->wavDir . '/' . $name;
                $size = (int) filesize($path);
                return [
                    'path' => $path,
                    'name' => $name,
                    'size' => $size,
                    'version' => "$name|$size|" . filemtime($path),
                ];
            }
        }
        return null;
    }

    /**
     * Імена, за якими шукається WAV (для підказок, якщо файл не знайдено).
     *
     * @return list<string>
     */
    public function wavCandidates(array $track): array
    {
        $names = [];
        if (!empty($track['wav_name'])) {
            $names[] = $track['wav_name'];
        }
        $names[] = pathinfo($track['file']['name'], PATHINFO_FILENAME) . '.wav';
        $names[] = $track['title'] . '.wav';

        return array_values(array_unique($names));
    }

    /**
     * Перечитує дані з Contentful просто зараз.
     */
    public function refresh(): void
    {
        $this->content->refresh();
        $this->wavIndex = null;
    }

    private function wavIndex(): array
    {
        if ($this->wavIndex === null) {
            $this->wavIndex = [];
            foreach (is_dir($this->wavDir) ? scandir($this->wavDir) : [] as $name) {
                if (is_file($this->wavDir . '/' . $name) && preg_match('/\.wave?$/i', $name)) {
                    $this->wavIndex[self::normalizeName($name)] = $name;
                }
            }
        }
        return $this->wavIndex;
    }

    /**
     * Порівняння імен файлів без урахування регістру, зайвих пробілів і розкладених «й», «ї»
     * (macOS може зберігати їх як дві окремі частини, а розширення intl на сервері немає).
     */
    private static function normalizeName(string $name): string
    {
        $name = strtr($name, [
            "и\u{0306}" => 'й', "И\u{0306}" => 'Й',
            "і\u{0308}" => 'ї', "І\u{0308}" => 'Ї',
            "е\u{0308}" => 'ё', "Е\u{0308}" => 'Ё',
        ]);
        $name = preg_replace('/\.wave?$/iu', '.wav', trim($name));
        $name = preg_replace(['/\s+/u', '/([(\[])\s+/u', '/\s+([)\]])/u'], [' ', '$1', '$1'], $name);

        return mb_strtolower($name);
    }

    public static function formatPrice(float $amount, string $currency): string
    {
        $number = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ' ');

        return match ($currency) {
            'UAH' => "$number грн",
            'USD' => "\$$number",
            default => "$number $currency",
        };
    }
}
