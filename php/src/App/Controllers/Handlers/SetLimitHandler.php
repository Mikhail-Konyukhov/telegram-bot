<?php

namespace App\Controllers\Handlers;

use App\Models\Limit as LimitModel;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /setlimit
 */
class SetLimitHandler
{
    private Client $tg;
    private LimitModel $limitModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->limitModel = new LimitModel();
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
            [$cmd, $category, $lim] = $m;
            $limit = (float)str_replace(',', '.', $lim);
            $this->limitModel->set($chatId, $category, $limit);
            $this->tg->sendMessage($chatId,
                "Лимит на категорию «{$category}» установлен: {$limit}"
            );
        } else {
            $this->tg->sendMessage($chatId, "Неверный формат. Пример: /setlimit еда 5000");
        }
    }
}
