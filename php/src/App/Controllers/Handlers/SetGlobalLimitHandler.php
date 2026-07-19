<?php

namespace App\Controllers\Handlers;

use App\Models\Limit as LimitModel;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /setgloballimit
 */
class SetGlobalLimitHandler
{
    private Client $tg;
    private LimitModel $limitModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->limitModel = new LimitModel();
    }

    /**
     * Обрабатывает команду /setgloballimit
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $msgText = trim($update->getMessage()->getText());
        $chatId = $update->getMessage()->getChat()->getId();

        if (preg_match('/^\/setgloballimit\s+([\d.,]+)$/i', $msgText, $m)) {
            [, $lim] = $m;
            $limit = (float)str_replace(',', '.', $lim);
            $this->limitModel->setGlobal($chatId, $limit);
            $this->tg->sendMessage($chatId,
                "Общий лимит на все категории установлен: {$limit}"
            );
        } else {
            $this->tg->sendMessage($chatId, "Неверный формат. Пример: /setgloballimit 50000");
        }
    }
}


