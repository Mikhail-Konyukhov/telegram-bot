<?php

namespace App\Controllers\Handlers;

use App\Models\User;
use App\Cfg;

use App\Services\DashboardService;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /dashboard в Telegram
 */
class DashboardHandler
{
    private Client $tg;
    private User $user;
    private string $dashboardUrl;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->user = new User();
        $this->dashboardUrl = (new Cfg())->getDashboardUrl();
    }

    /**
     * Обрабатывает команду /dashboard
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $chatId = $update->getMessage()->getChat()->getId();

        $token = $this->user->getToken($chatId);


        $baseUrl = $this->dashboardUrl ?? 'http://localhost/dashboard';

        // Формируем ссылку на дашборд
        $dashboardUrl = sprintf(
            '%s?user_id=%d&token=%s',
            rtrim($baseUrl, '/'),
            $chatId,
            urlencode($token)
        );

        $this->tg->sendMessage($chatId, $dashboardUrl);
    }

}
