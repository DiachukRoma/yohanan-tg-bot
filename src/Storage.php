<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Storage
{
    /** Покупцю показано реквізити */
    public const NEW = 'new';
    /** Покупець натиснув «Я оплатив», чекаємо на підтвердження */
    public const PAID = 'paid';
    /** Оплату підтверджено, файл надіслано */
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    private ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    public function __construct(private readonly array $db)
    {
    }

    /**
     * Підключення відкривається лише тоді, коли база справді потрібна.
     */
    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->db['host'], $this->db['port'], $this->db['name']),
                $this->db['user'],
                $this->db['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Справжні prepared statements: числа приходять числами, а не рядками
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $this->migrate();
        }
        return $this->pdo;
    }

    /**
     * Створює таблиці, якщо їх ще немає. Базу й користувача треба створити заздалегідь.
     */
    private function migrate(): void
    {
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id BIGINT NOT NULL PRIMARY KEY COMMENT 'Telegram user ID',
                username VARCHAR(64) NULL,
                first_name VARCHAR(255) NULL,
                last_name VARCHAR(255) NULL,
                phone VARCHAR(32) NULL,
                region VARCHAR(8) NULL COMMENT 'UA або INT',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) $options
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS orders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT NOT NULL,
                track_id VARCHAR(64) NOT NULL COMMENT 'ID запису в Contentful',
                amount DECIMAL(10, 2) NOT NULL,
                currency CHAR(3) NOT NULL,
                status VARCHAR(16) NOT NULL,
                admin_messages TEXT NULL,
                processed_by BIGINT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY orders_user_track (user_id, track_id, status),
                KEY orders_status (status)
            ) $options
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS file_cache (
                track_id VARCHAR(64) NOT NULL PRIMARY KEY,
                file_key VARCHAR(255) NOT NULL,
                file_id VARCHAR(255) NOT NULL
            ) $options
        ");
    }

    // ---- Користувачі ----

    public function upsertUser(array $from): array
    {
        $this->pdo()->prepare('
            INSERT INTO users (id, username, first_name, last_name, created_at, updated_at)
            VALUES (:id, :username, :first_name, :last_name, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                username = :username_upd,
                first_name = :first_name_upd,
                last_name = :last_name_upd,
                updated_at = :updated_at_upd
        ')->execute([
            'id' => (int) $from['id'],
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
            'created_at' => $now = self::now(),
            'updated_at' => $now,
            'username_upd' => $from['username'] ?? null,
            'first_name_upd' => $from['first_name'] ?? null,
            'last_name_upd' => $from['last_name'] ?? null,
            'updated_at_upd' => $now,
        ]);

        return $this->getUser((int) $from['id']);
    }

    public function getUser(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }
        $user['id'] = (int) $user['id'];
        return $user;
    }

    public function setPhone(int $userId, string $phone, string $region): void
    {
        $this->pdo()->prepare('UPDATE users SET phone = ?, region = ?, updated_at = ? WHERE id = ?')
            ->execute([$phone, $region, self::now(), $userId]);
    }

    public function setRegion(int $userId, string $region): void
    {
        $this->pdo()->prepare('UPDATE users SET region = ?, updated_at = ? WHERE id = ?')
            ->execute([$region, self::now(), $userId]);
    }

    // ---- Замовлення ----

    public function createOrder(int $userId, string $trackId, float $amount, string $currency): int
    {
        $now = self::now();
        $this->pdo()->prepare('
            INSERT INTO orders (user_id, track_id, amount, currency, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$userId, $trackId, $amount, $currency, self::NEW, $now, $now]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function getOrder(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findOrder(int $userId, string $trackId, string $status): ?array
    {
        $stmt = $this->pdo()->prepare('
            SELECT * FROM orders WHERE user_id = ? AND track_id = ? AND status = ? ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$userId, $trackId, $status]);
        return $stmt->fetch() ?: null;
    }

    public function ordersByStatus(string $status): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM orders WHERE status = ? ORDER BY id');
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    public function cancelOpenOrders(int $userId, string $trackId): void
    {
        $this->pdo()->prepare('
            UPDATE orders SET status = ?, updated_at = ? WHERE user_id = ? AND track_id = ? AND status = ?
        ')->execute([self::CANCELLED, self::now(), $userId, $trackId, self::NEW]);
    }

    /**
     * Атомарна зміна статусу. Повертає false, якщо замовлення вже не в статусі $from
     * (наприклад, два адміни натиснули «Підтвердити» одночасно).
     */
    public function transition(int $orderId, string $from, string $to, ?int $processedBy = null): bool
    {
        $stmt = $this->pdo()->prepare('
            UPDATE orders SET status = :to, updated_at = :now, processed_by = COALESCE(:by, processed_by)
            WHERE id = :id AND status = :from
        ');
        $stmt->execute(['to' => $to, 'now' => self::now(), 'by' => $processedBy, 'id' => $orderId, 'from' => $from]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return list<array{0: int, 1: int}> пари [chat_id, message_id]
     */
    public function adminMessages(int $orderId): array
    {
        $order = $this->getOrder($orderId);
        return $order && $order['admin_messages'] ? json_decode($order['admin_messages'], true) : [];
    }

    public function addAdminMessages(int $orderId, array $messages): void
    {
        if (!$messages) {
            return;
        }
        $all = array_merge($this->adminMessages($orderId), $messages);
        $this->pdo()->prepare('UPDATE orders SET admin_messages = ? WHERE id = ?')
            ->execute([json_encode($all), $orderId]);
    }

    public function hasPurchased(int $userId, string $trackId): bool
    {
        return $this->findOrder($userId, $trackId, self::APPROVED) !== null;
    }

    /**
     * @return list<string>
     */
    public function purchasedTrackIds(int $userId): array
    {
        $stmt = $this->pdo()->prepare('
            SELECT track_id FROM orders WHERE user_id = ? AND status = ?
            GROUP BY track_id ORDER BY MAX(updated_at) DESC
        ');
        $stmt->execute([$userId, self::APPROVED]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ---- Кеш file_id, щоб не завантажувати той самий файл у Telegram щоразу ----

    public function cachedFileId(string $trackId, string $fileKey): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT file_id FROM file_cache WHERE track_id = ? AND file_key = ?');
        $stmt->execute([$trackId, $fileKey]);
        return $stmt->fetchColumn() ?: null;
    }

    public function cacheFileId(string $trackId, string $fileKey, string $fileId): void
    {
        $this->pdo()->prepare('REPLACE INTO file_cache (track_id, file_key, file_id) VALUES (?, ?, ?)')
            ->execute([$trackId, $fileKey, $fileId]);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
