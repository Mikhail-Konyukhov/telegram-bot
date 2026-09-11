<?php

namespace App\Services;

use App\Cfg;
use App\Models\User;
use TelegramBot\Api\Client;

/**
 * Кто открыл клиент и с какой книгой трат он работает.
 *
 * Книга — это чат: в личке сам пользователь, в группе группа (её id приезжает
 * в `?startapp=`, право на неё подтверждает {@see LedgerResolver}). Незнакомый
 * владелец регистрируется автоматически — открыть приложение можно и не отправляя
 * боту /start.
 *
 * Путей два. Mini App приносит подписанный Telegram initData, браузер — cookie
 * {@see WebSession}. У второго проверять право на книгу нечем и незачем: ссылку
 * выдаёт бот в ответ на /web в самом чате, то есть тому, кто уже в нём пишет.
 */
class ApiAuthenticator
{
    private string $botToken;
    private LedgerResolver $ledgers;
    private User $users;

    public function __construct(?string $botToken = null, ?LedgerResolver $ledgers = null, ?User $users = null)
    {
        $this->botToken = $botToken ?? (new Cfg())->getTelegramBotToken();
        $this->ledgers = $ledgers ?? new LedgerResolver(new Client($this->botToken));
        $this->users = $users ?? new User();
    }

    /**
     * @param string $initData Значение заголовка X-Telegram-Init-Data
     * @param string $webSession Значение cookie веб-версии дашборда
     * @return int|null id книги трат; null — ни того, ни другого нет, подпись
     *                  неверна, срок вышел или пользователь не состоит в чате
     */
    public function authenticate(string $initData, string $webSession = ''): ?int
    {
        $ledgerId = $initData === ''
            ? WebSession::verifyCookie($webSession, $this->botToken)
            : $this->fromInitData($initData);

        if ($ledgerId === null) {
            return null;
        }

        $this->users->ensure($ledgerId);

        return $ledgerId;
    }

    /** Путь Mini App: подпись Telegram плюс проверка права на запрошенную книгу. */
    private function fromInitData(string $initData): ?int
    {
        $auth = TelegramAuth::verify($initData, $this->botToken);

        return $auth === null ? null : $this->ledgers->resolve($auth['user_id'], $auth['start_param']);
    }
}
