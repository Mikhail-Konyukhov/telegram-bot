<?php

namespace App\Controllers\Handlers;

use App\Models\Limit as LimitModel;
use App\Models\User;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /setlimit
 */
class SetLimitHandler
{
    private Client $tg;
    private LimitModel $limitModel;
    private User $userModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->limitModel = new LimitModel();
        $this->userModel = new User();
    }

    /**
     * Обрабатывает команду /setlimit
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $msgText = trim($update->getMessage()->getText());
        $chatId = $update->getMessage()->getChat()->getId();

        if (preg_match('/^\/setlimit\s+(\S+)\s+([\d.,]+)$/i', $msgText, $m)) {
            [, $category, $lim] = $m;
            $limit = (float)str_replace(',', '.', $lim);
            // limits ссылается на users.id — в группе владельца ещё может не быть
            $this->userModel->ensure($chatId);
            $this->limitModel->set($chatId, $category, $limit);
            $this->tg->sendMessage($chatId,
                "Лимит на категорию «{$category}» установлен: {$limit}"
            );
        } else {
            $this->tg->sendMessage($chatId, "Неверный формат. Пример: /setlimit еда 5000");
        }
    }
}
