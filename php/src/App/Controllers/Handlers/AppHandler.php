<?php

namespace App\Controllers\Handlers;

use App\Cfg;
use App\Models\User;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /app — открывает Mini App внутри Telegram.
 */
class AppHandler
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

    /**
     * Кнопка, открывающая Mini App в личном чате.
     *
     * Тип web_app принципиален: обычная url-кнопка выкинула бы пользователя
     * во внешний браузер, а вместе с ним из Telegram.
     *
     * @param string $url
     * @return InlineKeyboardMarkup
     */
    public static function button(string $url): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup([[
            ['text' => '📊 Открыть приложение', 'web_app' => ['url' => $url]],
        ]]);
    }

    /**
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $chat = $update->getMessage()->getChat();
        $chatId = $chat->getId();

        if ($chat->getType() === 'private') {
            $this->tg->sendMessage(
                $chatId,
                'Учёт расходов — графики, лимиты и редактирование трат:',
                null,
                false,
                null,
                self::button($this->cfg->getWebAppUrl())
            );
            return;
        }

        $this->sendGroupLink($chatId);
    }

    /**
     * Ссылка на общую книгу трат этого чата.
     *
     * Кнопки типа web_app в группах Telegram не показывает вообще — там работает
     * только direct link Mini App (заводится у @BotFather командой /newapp).
     * Какую книгу открывать, приложение узнаёт из `?startapp=`; право на неё
     * проверяется на сервере через getChatMember.
     *
     * @param int $chatId
     * @return void
     */
    private function sendGroupLink(int $chatId): void
    {
        $link = $this->cfg->getMiniAppLink();

        if ($link === null) {
            $this->tg->sendMessage(
                $chatId,
                'Приложение в группах пока не настроено: нужен direct link Mini App '
                . '(@BotFather → /newapp) и MINIAPP_LINK в конфиге бота.'
            );
            return;
        }

        // Книга появляется в момент, когда её впервые открывают: у expenses,
        // categories и limits внешний ключ на users.id.
        $this->userModel->ensure($chatId);

        $this->tg->sendMessage(
            $chatId,
            'Общий бюджет этой группы — траты всех участников в одном месте:',
            null,
            false,
            null,
            new InlineKeyboardMarkup([[
                ['text' => '📊 Открыть общий бюджет', 'url' => $link . '?startapp=' . $chatId],
            ]])
        );
    }
}
