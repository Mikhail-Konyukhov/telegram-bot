<?php

namespace App\Controllers\Handlers;

use App\Cfg;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /app — открывает Mini App внутри Telegram.
 */
class AppHandler
{
    private Client $tg;
    private string $webAppUrl;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->webAppUrl = (new Cfg())->getWebAppUrl();
    }

    /**
     * Кнопка, открывающая Mini App.
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
        $chatId = $update->getMessage()->getChat()->getId();

        $this->tg->sendMessage(
            $chatId,
            'Учёт расходов — графики, лимиты и редактирование трат:',
            null,
            false,
            null,
            self::button($this->webAppUrl)
        );
    }
}
