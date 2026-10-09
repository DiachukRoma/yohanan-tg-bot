<?php
declare(strict_types=1);

namespace App;

/**
 * Сценарій покупки:
 *   каталог → фонограма → «Купити» (реквізити, замовлення NEW)
 *   → «Я оплатив(ла)» (PAID, адмінам приходить запит)
 *   → адмін «Підтвердити» (APPROVED, покупець обирає формат: MP3 з Contentful або WAV з сервера)
 *     або «Відхилити» (REJECTED).
 */
final class Bot
{
    private const PAGE_SIZE = 8;
    private const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

    private const BTN_CATALOG = '🎵 Каталог';
    private const BTN_PURCHASES = '🧾 Мої покупки';
    private const BTN_SKIP = 'Пропустити';

    /** Відповідь на callback_query [текст, show_alert], яку надсилаємо наприкінці обробки */
    private ?array $toast = null;

    private readonly DownloadLink $links;

    public function __construct(
        private readonly Telegram $tg,
        private readonly Storage $db,
        private readonly Catalog $catalog,
        private readonly array $config,
    ) {
        $this->links = new DownloadLink($config['app_url'], $config['download_secret']);
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->onMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            }
        } catch (\Throwable $e) {
            Log::error('Update ' . ($update['update_id'] ?? '?') . ": $e");
            $chatId = $update['message']['chat']['id'] ?? $update['callback_query']['from']['id'] ?? null;
            if ($chatId !== null && ($update['message']['chat']['type'] ?? 'private') === 'private') {
                try {
                    $this->tg->sendMessage((int) $chatId, '😔 Щось пішло не так. Спробуйте, будь ласка, трохи пізніше.');
                } catch (\Throwable) {
                }
            }
        }
    }

    // ---- Вхідні повідомлення ----

    private function onMessage(array $msg): void
    {
        if (($msg['chat']['type'] ?? '') !== 'private' || !isset($msg['from'])) {
            return;
        }

        $chatId = (int) $msg['chat']['id'];
        $user = $this->db->upsertUser($msg['from']);

        if (isset($msg['contact'])) {
            $this->onContact($chatId, $user, $msg['contact']);
            return;
        }

        $text = trim($msg['text'] ?? '');

        match (true) {
            $text === '/start', str_starts_with($text, '/start ') => $this->start($chatId, $user),
            $text === self::BTN_SKIP => $this->skipRegion($chatId, $user),
            $text === self::BTN_CATALOG, $text === '/catalog' => $this->showCatalog($chatId, null, $user),
            $text === self::BTN_PURCHASES, $text === '/my' => $this->showPurchases($chatId, $user),
            $text === '/orders' && $this->isAdmin($user['id']) => $this->showPendingOrders($chatId),
            $text === '/refresh' && $this->isAdmin($user['id']) => $this->refreshContent($chatId),
            default => $this->sendMenu($chatId, 'Оберіть дію в меню нижче 👇'),
        };
    }

    private function onCallback(array $cb): void
    {
        $this->toast = null;
        $user = $this->db->upsertUser($cb['from']);
        $chatId = (int) ($cb['message']['chat']['id'] ?? $user['id']);
        $messageId = isset($cb['message']['message_id']) ? (int) $cb['message']['message_id'] : null;

        $parts = explode(':', (string) ($cb['data'] ?? ''));
        $action = $parts[0];
        $arg = $parts[1] ?? '';
        $page = (int) ($parts[2] ?? 0);

        try {
            match ($action) {
                'c' => $this->showCatalog($chatId, $messageId, $user, (int) $arg),
                't' => $this->showTrack($chatId, $messageId, $user, $arg, $page),
                'buy' => $this->buy($chatId, $messageId, $user, $arg),
                'paid' => $this->markPaid($chatId, $messageId, $user, (int) $arg),
                'cancel' => $this->cancelOrder($chatId, $messageId, $user, (int) $arg),
                'dl' => $this->redownload($chatId, $user, $arg, $parts[2] ?? ''),
                'ok' => $this->approve($user, (int) $arg),
                'no' => $this->reject($user, (int) $arg),
                default => null,
            };
        } finally {
            try {
                $this->tg->answerCallbackQuery($cb['id'], $this->toast[0] ?? null, $this->toast[1] ?? false);
            } catch (TelegramException) {
                // запит міг застаріти, поки надсилався великий файл — це не критично
            }
        }
    }

    // ---- Визначення регіону (ціна в гривнях чи доларах) ----

    private function start(int $chatId, array $user): void
    {
        if ($user['phone'] === null) {
            $this->askRegion($chatId);
            return;
        }
        $this->sendMenu($chatId, $this->catalog->settings()['welcome_text'] ?: 'Вітаю! 👋 Оберіть фонограму в каталозі.');
        $this->showCatalog($chatId, null, $user);
    }

    private function askRegion(int $chatId): void
    {
        $this->tg->sendMessage(
            $chatId,
            ($this->catalog->settings()['welcome_text'] ?: 'Вітаю! 👋 Тут можна придбати фонограми.') . "\n\n"
            . "Щоб показати ціни у вашій валюті, поділіться, будь ласка, номером телефону кнопкою нижче. "
            . "Номер використовується лише для підтвердження покупки.",
            [
                'keyboard' => [
                    [['text' => '📱 Поділитися номером', 'request_contact' => true]],
                    [['text' => self::BTN_SKIP]],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        );
    }

    private function onContact(int $chatId, array $user, array $contact): void
    {
        // Приймаємо лише власний номер, а не пересланий чужий контакт
        if ((int) ($contact['user_id'] ?? 0) !== $user['id']) {
            $this->tg->sendMessage($chatId, 'Будь ласка, надішліть свій номер кнопкою «📱 Поділитися номером».');
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $contact['phone_number']);
        $region = str_starts_with($digits, '380') ? Catalog::UA : Catalog::INT;
        $this->db->setPhone($user['id'], '+' . $digits, $region);

        $this->sendMenu($chatId, $region === Catalog::UA
            ? 'Дякуємо! Ціни буде показано в гривнях 🇺🇦'
            : 'Дякуємо! Ціни буде показано в доларах США.');
        $this->showCatalog($chatId, null, $this->db->getUser($user['id']));
    }

    private function skipRegion(int $chatId, array $user): void
    {
        if ($user['region'] === null) {
            $this->db->setRegion($user['id'], Catalog::INT);
            $user['region'] = Catalog::INT;
        }
        $this->sendMenu(
            $chatId,
            "Добре! Ціни буде показано в доларах США.\n\n"
            . 'Якщо ви в Україні, надішліть /start і поділіться номером, щоб бачити ціни в гривнях.',
        );
        $this->showCatalog($chatId, null, $user);
    }

    // ---- Каталог і покупка ----

    private function showCatalog(int $chatId, ?int $messageId, array $user, int $page = 0): void
    {
        if ($user['region'] === null) {
            $this->askRegion($chatId);
            return;
        }

        $tracks = $this->catalog->forSale();
        if (!$tracks) {
            $this->show($chatId, $messageId, 'Каталог поки порожній. Загляньте трохи згодом 🙏');
            return;
        }

        $pages = (int) ceil(count($tracks) / self::PAGE_SIZE);
        $page = max(0, min($page, $pages - 1));

        $rows = [];
        foreach (array_slice($tracks, $page * self::PAGE_SIZE, self::PAGE_SIZE) as $track) {
            $price = $this->catalog->price($track, $user['region']);
            $rows[] = [self::button(
                $track['title'] . ' — ' . Catalog::formatPrice($price['amount'], $price['currency']),
                "t:{$track['id']}:$page",
            )];
        }

        $nav = [];
        if ($page > 0) {
            $nav[] = self::button('◀️', 'c:' . ($page - 1));
        }
        if ($page < $pages - 1) {
            $nav[] = self::button('▶️', 'c:' . ($page + 1));
        }
        if ($nav) {
            $rows[] = $nav;
        }

        $text = '🎵 <b>Каталог фонограм</b>' . ($pages > 1 ? ' (сторінка ' . ($page + 1) . " з $pages)" : '')
            . "\n\nОберіть фонограму:";
        $this->show($chatId, $messageId, $text, $rows);
    }

    private function showTrack(int $chatId, ?int $messageId, array $user, string $trackId, int $page): void
    {
        $track = $this->catalog->get($trackId);
        if ($track === null) {
            $this->toast = ['Фонограму не знайдено'];
            return;
        }
        if ($user['region'] === null) {
            $this->askRegion($chatId);
            return;
        }

        $price = $this->catalog->price($track, $user['region']);
        $text = '🎵 <b>' . self::e($track['title']) . "</b>\n";
        if (!empty($track['description'])) {
            $text .= "\n" . self::e($track['description']) . "\n";
        }
        $text .= "\n💰 Ціна: <b>" . Catalog::formatPrice($price['amount'], $price['currency']) . '</b>';
        $text .= "\n🎧 Формати: " . ($this->catalog->wavFile($track) ? 'MP3 і WAV' : 'MP3');

        if ($this->db->hasPurchased($user['id'], $trackId)) {
            $text .= "\n\n✅ Ви вже придбали цю фонограму. Оберіть формат для завантаження:";
            $rows = $this->formatRows($track);
        } else {
            $rows = [[self::button('💳 Купити', "buy:$trackId")]];
        }
        $rows[] = [self::button('← До каталогу', "c:$page")];

        $this->show($chatId, $messageId, $text, $rows);
    }

    private function buy(int $chatId, ?int $messageId, array $user, string $trackId): void
    {
        $track = $this->catalog->get($trackId);
        if ($track === null || $track['hidden']) {
            $this->toast = ['Ця фонограма зараз недоступна', true];
            return;
        }
        if ($user['region'] === null) {
            $this->askRegion($chatId);
            return;
        }
        if ($this->db->hasPurchased($user['id'], $trackId)) {
            $this->offerFormats($chatId, $track, '✅ Ви вже придбали цю фонограму.');
            return;
        }
        if ($pending = $this->db->findOrder($user['id'], $trackId, Storage::PAID)) {
            $this->toast = ["Ваш запит #{$pending['id']} уже на перевірці. Очікуйте, будь ласка 🙏", true];
            return;
        }

        // Попередні неоплачені замовлення на цю фонограму більше не потрібні
        $this->db->cancelOpenOrders($user['id'], $trackId);

        $price = $this->catalog->price($track, $user['region']);
        $orderId = $this->db->createOrder($user['id'], $trackId, $price['amount'], $price['currency']);

        $text = "🧾 <b>Замовлення #$orderId</b>\n"
            . '🎵 ' . self::e($track['title']) . "\n"
            . '💰 До сплати: <b>' . Catalog::formatPrice($price['amount'], $price['currency']) . "</b>\n\n"
            . $this->catalog->settings()['payment'][$user['region']] . "\n\n"
            . "Якщо є можливість, вкажіть у коментарі до платежу <code>#$orderId</code>.\n\n"
            . 'Після оплати натисніть кнопку нижче 👇';

        $this->show($chatId, $messageId, $text, [
            [self::button('✅ Я оплатив(ла)', "paid:$orderId")],
            [self::button('✖️ Скасувати', "cancel:$orderId")],
        ]);
    }

    private function markPaid(int $chatId, ?int $messageId, array $user, int $orderId): void
    {
        $order = $this->db->getOrder($orderId);
        if ($order === null || (int) $order['user_id'] !== $user['id']) {
            $this->toast = ['Замовлення не знайдено'];
            return;
        }

        if (!$this->db->transition($orderId, Storage::NEW, Storage::PAID)) {
            $this->toast = [match ($order['status']) {
                Storage::PAID => 'Запит уже на перевірці. Очікуйте, будь ласка 🙏',
                Storage::APPROVED => 'Це замовлення вже виконано',
                default => 'Замовлення неактивне. Оформіть нове через каталог.',
            }, true];
            return;
        }

        $this->show(
            $chatId,
            $messageId,
            "⏳ <b>Замовлення #$orderId</b> надіслано на перевірку.\n\n"
            . 'Щойно оплату буде підтверджено, фонограма прийде сюди, в цей чат 🙏',
        );

        $this->sendAdminCards($this->db->getOrder($orderId), $this->config['admin_ids']);
    }

    private function cancelOrder(int $chatId, ?int $messageId, array $user, int $orderId): void
    {
        $order = $this->db->getOrder($orderId);
        if ($order === null || (int) $order['user_id'] !== $user['id']) {
            $this->toast = ['Замовлення не знайдено'];
            return;
        }
        if (!$this->db->transition($orderId, Storage::NEW, Storage::CANCELLED)) {
            $this->toast = ['Це замовлення вже не можна скасувати', true];
            return;
        }
        $this->show($chatId, $messageId, "Замовлення #$orderId скасовано.", [
            [self::button('🎵 До каталогу', 'c:0')],
        ]);
    }

    /**
     * Видача купленої фонограми: без формату — показує вибір MP3/WAV.
     */
    private function redownload(int $chatId, array $user, string $trackId, string $format = ''): void
    {
        $track = $this->catalog->get($trackId);
        if ($track === null || !$this->db->hasPurchased($user['id'], $trackId)) {
            $this->toast = ['Цю фонограму ще не придбано', true];
            return;
        }

        try {
            match ($format) {
                'mp3' => $this->deliverMp3($chatId, $track),
                'wav' => $this->deliverWav($chatId, $user['id'], $track),
                default => $this->offerFormats($chatId, $track),
            };
        } catch (\Throwable $e) {
            Log::error("Надсилання $format фонограми $trackId користувачу {$user['id']}: " . $e->getMessage());
            $this->toast = ['Не вдалося надіслати файл. Напишіть, будь ласка, ' . $this->catalog->settings()['support_contact'], true];
        }
    }

    private function offerFormats(int $chatId, array $track, ?string $intro = null): void
    {
        $this->tg->sendMessage(
            $chatId,
            ($intro !== null ? "$intro\n\n" : '') . '🎵 <b>' . self::e($track['title']) . "</b>\n\nОберіть формат:",
            self::inline($this->formatRows($track)),
        );
    }

    /**
     * Кнопки форматів. WAV показується, лише якщо файл є на сервері.
     */
    private function formatRows(array $track): array
    {
        $row = [self::button(sprintf('🎧 MP3 (%s)', self::formatSize($track['file']['size'])), "dl:{$track['id']}:mp3")];
        if ($wav = $this->catalog->wavFile($track)) {
            $row[] = self::button(sprintf('💿 WAV (%s)', self::formatSize($wav['size'])), "dl:{$track['id']}:wav");
        }
        return [$row];
    }

    private function showPurchases(int $chatId, array $user): void
    {
        $rows = [];
        foreach ($this->db->purchasedTrackIds($user['id']) as $trackId) {
            if ($track = $this->catalog->get($trackId)) {
                $rows[] = [self::button('📥 ' . $track['title'], "dl:$trackId")];
            }
        }

        if (!$rows) {
            $this->sendMenu($chatId, 'У вас поки немає покупок. Загляньте в каталог 🎵');
            return;
        }
        $this->tg->sendMessage($chatId, '🧾 <b>Ваші покупки</b>. Натисніть, щоб отримати файл:', self::inline($rows));
    }

    // ---- Адмінська частина ----

    private function approve(array $admin, int $orderId): void
    {
        if (!$this->isAdmin($admin['id'])) {
            $this->toast = ['Недостатньо прав'];
            return;
        }
        if (!$this->db->transition($orderId, Storage::PAID, Storage::APPROVED, $admin['id'])) {
            $this->toast = ['Цей запит уже оброблено', true];
            return;
        }

        $order = $this->db->getOrder($orderId);
        try {
            $track = $this->catalog->get($order['track_id'])
                ?? throw new \RuntimeException('фонограми немає в Contentful (можливо, її знято з публікації)');
            $this->offerFormats(
                (int) $order['user_id'],
                $track,
                '✅ Оплату підтверджено! Дякуємо за покупку 💛' . "\n"
                . 'Завантажити фонограму можна будь-коли й у будь-якому форматі через «🧾 Мої покупки».',
            );
        } catch (\Throwable $e) {
            // Повертаємо запит на перевірку, щоб його можна було підтвердити повторно
            $this->db->transition($orderId, Storage::APPROVED, Storage::PAID);
            Log::error("Не вдалося повідомити покупця про підтвердження #$orderId: " . $e->getMessage());
            $this->toast = ['Не вдалося повідомити покупця: ' . mb_substr($e->getMessage(), 0, 150), true];
            return;
        }

        $this->closeAdminCards($order, '✅ Підтверджено — ' . self::e($admin['first_name'] ?? (string) $admin['id']));
        $this->toast = ['Покупцю надіслано кнопки для завантаження'];
    }

    private function reject(array $admin, int $orderId): void
    {
        if (!$this->isAdmin($admin['id'])) {
            $this->toast = ['Недостатньо прав'];
            return;
        }
        if (!$this->db->transition($orderId, Storage::PAID, Storage::REJECTED, $admin['id'])) {
            $this->toast = ['Цей запит уже оброблено', true];
            return;
        }

        $order = $this->db->getOrder($orderId);
        $track = $this->catalog->get($order['track_id']);
        try {
            $this->tg->sendMessage(
                (int) $order['user_id'],
                "❌ На жаль, оплату за замовленням <b>#$orderId</b>"
                . ($track ? ' (' . self::e($track['title']) . ')' : '') . " не знайдено.\n\n"
                . 'Якщо ви впевнені, що оплатили, напишіть, будь ласка, '
                . self::e($this->catalog->settings()['support_contact']) . ' і вкажіть номер замовлення.',
            );
        } catch (TelegramException $e) {
            Log::error("Не вдалося повідомити покупця про відхилення #$orderId: " . $e->getMessage());
        }

        $this->closeAdminCards($order, '❌ Відхилено — ' . self::e($admin['first_name'] ?? (string) $admin['id']));
        $this->toast = ['Запит відхилено, покупця повідомлено'];
    }

    private function showPendingOrders(int $chatId): void
    {
        $orders = $this->db->ordersByStatus(Storage::PAID);
        if (!$orders) {
            $this->tg->sendMessage($chatId, 'Немає запитів, що чекають на підтвердження 👌');
            return;
        }
        foreach ($orders as $order) {
            $this->sendAdminCards($order, [$chatId]);
        }
    }

    private function sendAdminCards(array $order, array $adminIds): void
    {
        $text = $this->adminOrderText($order);
        $markup = self::inline([[
            self::button('✅ Підтвердити', "ok:{$order['id']}"),
            self::button('❌ Відхилити', "no:{$order['id']}"),
        ]]);

        $sent = [];
        foreach ($adminIds as $adminId) {
            try {
                $message = $this->tg->sendMessage((int) $adminId, $text, $markup);
                $sent[] = [(int) $adminId, (int) $message['message_id']];
            } catch (TelegramException $e) {
                Log::error("Не вдалося надіслати запит #{$order['id']} адміну $adminId: " . $e->getMessage());
            }
        }
        $this->db->addAdminMessages((int) $order['id'], $sent);
    }

    /**
     * Прибирає кнопки з усіх карток цього запиту в усіх адмінів і дописує результат.
     */
    private function closeAdminCards(array $order, string $status): void
    {
        $text = $this->adminOrderText($order) . "\n\n<b>$status</b>";
        foreach ($this->db->adminMessages((int) $order['id']) as [$chatId, $messageId]) {
            try {
                $this->tg->editMessageText($chatId, $messageId, $text);
            } catch (TelegramException) {
                // повідомлення могло бути видалене
            }
        }
    }

    private function adminOrderText(array $order): string
    {
        $user = $this->db->getUser((int) $order['user_id']);
        $track = $this->catalog->get($order['track_id']);
        $name = self::e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?: 'Без імені';

        return implode("\n", [
            "🛒 <b>Запит на покупку #{$order['id']}</b>",
            '',
            "👤 <a href=\"tg://user?id={$order['user_id']}\">$name</a>"
                . (!empty($user['username']) ? ' @' . self::e($user['username']) : ''),
            '📱 ' . (!empty($user['phone']) ? self::e($user['phone']) : 'номер не надано'),
            '🎵 ' . ($track ? self::e($track['title']) : self::e($order['track_id']) . ' (немає в Contentful)'),
            '💰 <b>' . Catalog::formatPrice((float) $order['amount'], $order['currency']) . '</b>',
            '🕒 ' . date('d.m.Y H:i', strtotime($order['created_at'])),
        ]);
    }

    // ---- Надсилання файлу ----

    private function deliverMp3(int $chatId, array $track): void
    {
        $file = $track['file'];
        $this->sendDocumentCached($chatId, $track['id'], $file['version'], $file['size'], $file['name'], static function (callable $send) use ($file) {
            $tmp = tempnam(sys_get_temp_dir(), 'fonogram');
            try {
                Http::download($file['url'], $tmp);
                return $send(new \CURLFile($tmp, $file['mime'], $file['name']));
            } finally {
                @unlink($tmp);
            }
        }, '🎵 ' . self::e($track['title']) . ' — MP3');
    }

    private function deliverWav(int $chatId, int $userId, array $track): void
    {
        $file = $this->catalog->wavFile($track)
            ?? throw new \RuntimeException('WAV не знайдено на сервері, шукали: ' . implode(', ', $this->catalog->wavCandidates($track)));
        $caption = '🎵 ' . self::e($track['title']) . ' — WAV';

        // Великі WAV Telegram не прийме від бота — даємо посилання на завантаження з нашого сервера
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            if (!$this->links->isAvailable()) {
                throw new \RuntimeException('WAV більший за 50 МБ, а APP_URL у .env не задано — посилання не створити');
            }
            $hours = intdiv(DownloadLink::TTL, 3600);
            $this->tg->sendMessage(
                $chatId,
                "$caption (" . self::formatSize($file['size']) . ")\n\n"
                . "Файл завеликий для Telegram, тому завантажте його за посиланням нижче.\n"
                . "Посилання діє $hours год. Нове завжди можна отримати в «🧾 Мої покупки».",
                self::inline([[['text' => '⬇️ Завантажити WAV', 'url' => $this->links->create($userId, $track['id'], 'wav')]]]),
            );
            return;
        }

        $this->sendDocumentCached($chatId, $track['id'] . ':wav', $file['version'], $file['size'], $file['name'], static fn (callable $send) =>
            $send(new \CURLFile($file['path'], 'audio/wav', $file['name'])), $caption);
    }

    /**
     * Надсилає файл документом. Після першого завантаження Telegram дає file_id — далі надсилаємо за ним,
     * без повторного аплоаду. Якщо файл замінили, змінюється $version і файл завантажується заново.
     *
     * @param callable(callable(\CURLFile): array): array $upload
     */
    private function sendDocumentCached(int $chatId, string $cacheKey, string $version, int $size, string $name, callable $upload, string $caption): void
    {
        if ($fileId = $this->db->cachedFileId($cacheKey, $version)) {
            try {
                $this->tg->sendDocument($chatId, $fileId, $caption);
                return;
            } catch (TelegramException $e) {
                Log::error("file_id для $cacheKey не спрацював, завантажую заново: " . $e->getMessage());
            }
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(sprintf('файл %s завеликий (%s), Telegram приймає від ботів до 50 МБ', $name, self::formatSize($size)));
        }

        $result = $upload(fn (\CURLFile $file) => $this->tg->sendDocument($chatId, $file, $caption));

        $fileId = $result['document']['file_id'] ?? $result['audio']['file_id'] ?? null;
        if ($fileId !== null) {
            $this->db->cacheFileId($cacheKey, $version, $fileId);
        }
    }

    // ---- Оновлення даних з Contentful ----

    private function refreshContent(int $chatId): void
    {
        try {
            $this->catalog->refresh();
        } catch (\Throwable $e) {
            Log::error('Ручне оновлення з Contentful: ' . $e->getMessage());
            $this->tg->sendMessage($chatId, '❌ Не вдалося оновити дані з Contentful: ' . self::e($e->getMessage()));
            return;
        }
        $tracks = $this->catalog->forSale();
        $withoutWav = array_filter($tracks, fn (array $track) => $this->catalog->wavFile($track) === null);

        $text = '🔄 Дані з Contentful оновлено. Фонограм у продажу: ' . count($tracks);
        if ($withoutWav) {
            $text .= "\n\n⚠️ Без WAV на сервері (" . count($withoutWav) . "):\n";
            foreach ($withoutWav as $track) {
                $text .= '• ' . self::e($track['title']) . ' — очікується <code>'
                    . self::e($this->catalog->wavCandidates($track)[0]) . "</code>\n";
            }
        } else {
            $text .= "\n✅ WAV є для всіх фонограм.";
        }
        $this->tg->sendMessage($chatId, $text);
    }

    // ---- Допоміжне ----

    private function isAdmin(int $userId): bool
    {
        return in_array($userId, array_map('intval', $this->config['admin_ids']), true);
    }

    private function sendMenu(int $chatId, string $text): void
    {
        $this->tg->sendMessage($chatId, $text, [
            'keyboard' => [[['text' => self::BTN_CATALOG], ['text' => self::BTN_PURCHASES]]],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ]);
    }

    /**
     * Редагує повідомлення з кнопками, а якщо не вийшло (або редагувати нічого), надсилає нове.
     */
    private function show(int $chatId, ?int $messageId, string $text, ?array $rows = null): void
    {
        $markup = $rows !== null ? self::inline($rows) : null;
        if ($messageId !== null) {
            try {
                $this->tg->editMessageText($chatId, $messageId, $text, $markup);
                return;
            } catch (TelegramException) {
                // наприклад, повідомлення застаре — надішлемо нове
            }
        }
        $this->tg->sendMessage($chatId, $text, $markup);
    }

    private static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    private static function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    private static function formatSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / 1024 / 1024, 1, ',', '') . ' МБ'
            : max(1, (int) round($bytes / 1024)) . ' КБ';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
