<?php

namespace App\Tests\Support;

use TelegramBot\Api\Client;
use TelegramBot\Api\Types\ChatMember;

/**
 * Заглушка Telegram-клиента для проверки {@see \App\Services\LedgerResolver}.
 *
 * Обычный мок PHPUnit здесь не работает: `Client` не объявляет `getChatMember`,
 * а проксирует его во внутренний `BotApi` через `__call`, и замокать магический
 * метод нельзя. Единственная альтернатива — `getMockBuilder()->addMethods()`,
 * но он объявлен устаревшим и удалён в PHPUnit 12, поэтому метод объявляется
 * здесь явно.
 */
final class FakeTelegramClient extends Client
{
    /** Сколько раз спросили Telegram — на этом держатся проверки кэша. */
    public int $getChatMemberCalls = 0;

    private ?string $status;
    private ?\Throwable $error;

    public function __construct(?string $status = null, ?\Throwable $error = null)
    {
        // parent::__construct() намеренно не вызывается: он требует токен бота и
        // поднимает BotApi, а из всего клиента используется один метод ниже.
        $this->status = $status;
        $this->error = $error;
    }

    /**
     * @param int|string $chatId
     * @param int $userId
     */
    public function getChatMember($chatId, $userId): ChatMember
    {
        $this->getChatMemberCalls++;

        if ($this->error !== null) {
            throw $this->error;
        }

        return ChatMember::fromResponse([
            'user'   => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Тест'],
            'status' => $this->status,
        ]);
    }
}
