<?php
declare(strict_types=1);

namespace App;

/**
 * Мінімальний клієнт Telegram Bot API.
 */
class Telegram
{
    private const API_URL = 'https://api.telegram.org/bot';

    public function __construct(private readonly string $token)
    {
    }

    /**
     * @throws TelegramException
     */
    public function call(string $method, array $params = []): mixed
    {
        $hasFile = false;
        foreach ($params as $value) {
            if ($value instanceof \CURLFile) {
                $hasFile = true;
                break;
            }
        }

        if ($hasFile) {
            // multipart/form-data: вкладені масиви (reply_markup тощо) передаються як JSON-рядки
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $params[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            $options = [CURLOPT_POSTFIELDS => $params];
        } else {
            $options = [
                CURLOPT_POSTFIELDS => json_encode($params ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ];
        }

        $ch = curl_init(self::API_URL . $this->token . '/' . $method);
        // CURLOPT_POST має йти ПЕРЕД CURLOPT_POSTFIELDS: інакше curl перемикається з multipart
        // на звичайний POST з порожнім тілом, і Telegram відповідає «there is no document in the request»
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
        ] + $options);
        $raw = curl_exec($ch);

        if ($raw === false) {
            throw new TelegramException("$method: " . curl_error($ch));
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            throw new TelegramException(
                "$method: " . ($data['description'] ?? $raw),
                (int) ($data['error_code'] ?? 0),
            );
        }

        return $data['result'];
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $replyMarkup,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Без $replyMarkup інлайн-кнопки з повідомлення прибираються.
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        try {
            $this->call('editMessageText', array_filter([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => $replyMarkup,
            ], static fn ($v) => $v !== null));
        } catch (TelegramException $e) {
            if (!str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }
        }
    }

    /**
     * @param string|\CURLFile $document file_id вже завантаженого файлу або новий файл
     */
    public function sendDocument(int $chatId, string|\CURLFile $document, ?string $caption = null): array
    {
        return $this->call('sendDocument', array_filter([
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], static fn ($v) => $v !== null));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert ?: null,
        ], static fn ($v) => $v !== null));
    }
}
