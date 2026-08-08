<?php

namespace App\Services;

use App\Models\ChatMember;
use TelegramBot\Api\Client;

/**
 * Определяет, с какой книгой трат работает открывший Mini App.
 *
 * Книга = чат. В личке это сам пользователь, в группе — сама группа: бот и так
 * пишет траты под `chat_id`, поэтому в группе получается общий семейный бюджет
 * без отдельной сущности «домохозяйство».
 *
 * Какую книгу открывать, приложение сообщает через `?startapp=` direct link.
 * Значение подписано вместе с остальным initData, но подпись говорит лишь о том,
 * что параметр не подменили по дороге, — сам параметр выбирает клиент. Поэтому
 * право на чужую книгу проверяется у Telegram: состоит ли человек в этом чате.
 */
class LedgerResolver
{
    /** Сколько доверять проверке членства. Час — компромисс между лишними
     *  запросами в Telegram и задержкой отзыва доступа при выходе из группы. */
    private const MEMBERSHIP_TTL = 3600;

    /** Статусы getChatMember, при которых человек считается участником. */
    private const MEMBER_STATUSES = ['creator', 'administrator', 'member', 'restricted'];

    private Client $tg;
    private ChatMember $members;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->members = new ChatMember();
    }

    /**
     * @param int $userId Кто открыл приложение (из подписанного initData)
     * @param string|null $startParam Значение ?startapp=
     * @return int|null id книги трат, либо null — доступ запрещён
     */
    public function resolve(int $userId, ?string $startParam): ?int
    {
        if ($startParam === null || !preg_match('/^-?\d+$/', $startParam)) {
            return $userId;
        }

        $ledger = (int)$startParam;

        // Личные книги чужими быть не могут: id групп и супергрупп в Telegram
        // всегда отрицательные, поэтому всё неотрицательное — это либо ты сам,
        // либо попытка открыть чужую личную книгу.
        if ($ledger >= 0) {
            return $ledger === $userId ? $userId : null;
        }

        return $this->isMember($ledger, $userId) ? $ledger : null;
    }

    /**
     * Состоит ли пользователь в чате. Свежий положительный ответ берётся из кэша.
     */
    private function isMember(int $chatId, int $userId): bool
    {
        if ($this->members->isFresh($chatId, $userId, self::MEMBERSHIP_TTL)) {
            return true;
        }

        try {
            $status = $this->tg->getChatMember($chatId, $userId)->getStatus();
        } catch (\Throwable $e) {
            // Бота выкинули из чата, чат удалён, Telegram недоступен. Отказ здесь
            // безопаснее доступа, а закэшированное «да» продолжит работать до TTL.
            error_log("getChatMember({$chatId}) failed: " . $e->getMessage());
            return false;
        }

        if (!in_array($status, self::MEMBER_STATUSES, true)) {
            $this->members->forget($chatId, $userId);
            return false;
        }

        $this->members->remember($chatId, $userId);

        return true;
    }
}
