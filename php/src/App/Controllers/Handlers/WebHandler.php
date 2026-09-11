<?php

namespace App\Controllers\Handlers;

use App\Cfg;
use App\Models\User;
use App\Services\WebSession;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Update;

/**
 * Команда /web — те же экраны, что в Mini App, но в обычном браузере.
 *
 * Книга трат — чат, откуда пришла команда: в личке личная, в группе общая.
 * Право на групповую книгу подтверждает сам факт сообщения в этот чат, поэтому
 * getChatMember здесь не нужен — в отличие от Mini App, где книгу называет
 * клиентский `?startapp=` (см. {@see \App\Services\LedgerResolver}).
 *
 * Кнопка типа url, а не web_app: ссылка должна открываться внешним браузером,
 * да и web_app-кнопок Telegram в группах не показывает вовсе.
 */
class WebHandler
{
    private Client $tg;
    private Cfg $cfg;
    private User $userModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->cfg = new Cfg();
        $this->userModel = new User();
    }

    public function handle(Update $update): void
    {
        $chatId = $update->getMessage()->getChat()->getId();
        $url = $this->cfg->getWebUrl();

        if ($url === null) {
            $this->tg->sendMessage($chatId, 'Веб-версия не настроена: нужен WEB_URL в конфиге бота.');
            return;
        }

        // Книга появляется в момент, когда её впервые открывают: у expenses,
        // categories и limits внешний ключ на users.id.
        $this->userModel->ensure($chatId);

        $link = $url . '?t=' . WebSession::link($chatId, $this->cfg->getTelegramBotToken());

        $this->tg->sendMessage(
            $chatId,
            "Дашборд в браузере — те же графики, лимиты и правка трат.\n"
            . 'Ссылка действует неделю; когда перестанет открываться, отправьте /web заново.',
            null,
            false,
            null,
            new InlineKeyboardMarkup([[
                ['text' => '🖥 Открыть в браузере', 'url' => $link],
            ]])
        );
    }
}
