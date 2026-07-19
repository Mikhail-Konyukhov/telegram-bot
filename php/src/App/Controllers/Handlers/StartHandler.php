<?php

namespace App\Controllers\Handlers;

use App\Models\User;
use Random\RandomException;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /start
 */
class StartHandler
{
    private Client $tg;
    private User $userModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->userModel = new User();
    }

    /**
     * Обрабатывает команду /start
     *
     * @param Update $update
     * @return void
     * @throws RandomException
     */
    public function handle(Update $update): void
    {
        $chatId = $update->getMessage()->getChat()->getId();

        if (!$this->userModel->exists($chatId)) {
            error_log($chatId);
            error_log("регаем");
            try {
                $token = $this->userModel->register($chatId);
                $message = "🎉 Добро пожаловать в бота учета расходов!\n\n";
                $message .= "🔑 Ваш токен для дашборда: `$token`\n\n";
                $message .= "📋 **Доступные команды:**\n";
                $message .= "/dashboard - получить ссылку на веб-дашборд\n";
                $message .= "/categories - управление категориями\n";
                $message .= "/setlimit - установить лимит расходов\n\n";
                $message .= "💡 **Как использовать:**\n";
                $message .= "Просто отправьте расходы в формате:\n";
                $message .= "• кофе 200\n";
                $message .= "• такси 300, обед 450\n\n";
                $message .= "Расходы будут автоматически классифицированы по категориям!";
                
                $this->tg->sendMessage($chatId, $message);
            } catch (\Exception $e) {
                error_log($e->getMessage());
                $this->tg->sendMessage($chatId, "❌ Произошла ошибка при регистрации.");
            }
        } else {
            $token = $this->userModel->getToken($chatId);
            $message = "👋 Вы уже зарегистрированы!\n\n";
            $message .= "🔑 Ваш токен для дашборда: `$token`\n\n";
            $message .= "📋 **Доступные команды:**\n";
            $message .= "/dashboard - получить ссылку на веб-дашборд\n";
            $message .= "/categories - управление категориями\n";
            $message .= "/setlimit - установить лимит расходов\n\n";
            $message .= "💡 Отправьте расходы в формате: кофе 200, такси 300";
            
            $this->tg->sendMessage($chatId, $message);
        }
    }
}
