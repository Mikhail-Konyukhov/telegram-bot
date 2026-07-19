<?php

namespace App\Controllers\Handlers;

use App\Models\Expense;
use App\Models\Limit as LimitModel;
use App\Models\Category;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Exception\ConnectException;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает сообщения с расходами (по умолчанию)
 */
class ExpenseHandler
{
    private Client $tg;
    private HttpClient $http;
    private Expense $expenseModel;
    private LimitModel $limitModel;
    private Category $categoryModel;
    private string $classifierUrl;

    public function __construct(Client $tg, HttpClient $http, string $classifierUrl)
    {
        $this->tg = $tg;
        $this->http = $http;
        $this->classifierUrl = $classifierUrl;

        $this->expenseModel = new Expense();
        $this->limitModel = new LimitModel();
        $this->categoryModel = new Category();
    }

    /**
     * Обрабатывает сообщение с расходами асинхронно
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        try {
            $msgText = trim($update->getMessage()->getText());
            $chatId = $update->getMessage()->getChat()->getId();
            $items = preg_split('/[;,]+/', $msgText, -1, PREG_SPLIT_NO_EMPTY);
            $errors = [];
            $userCategories = $this->categoryModel->getUserCategories($chatId);
            $messageDate = $this->getOriginalMessageDate($update);
            $date = date('Y-m-d H:i:s', $messageDate); // для базы
            $promises = $this->buildClassificationPromises($items, $userCategories);
            $results = Promise\Utils::settle($promises)->wait();
            $addedExpenses = [];
            $limitWarnings = [];
            foreach ($results as $idx => $result) {
                $item = $items[$idx];
                if ($result['state'] === 'fulfilled') {
                    $response = $result['value'];
                    $data = json_decode($response->getBody()->getContents(), true);
                    if (empty($data['items'][0])) {
                        $errors[] = "Не удалось классифицировать «{$item}»";
                        continue;
                    }
                    $it = $data['items'][0];
                    $expense = $this->classifyAndAddExpense($chatId, $it, $date);
                    $addedExpenses[] = $expense;
                } else {
                    $reason = $result['reason'];
                    $errors[] = "Ошибка при обработке «{$item}»: " . $reason->getMessage();
                }
            }
            if ($errors) {
                $this->tg->sendMessage($chatId, implode("\n", $errors));
            } else {
                // Собираем все уведомления о лимитах
                $limitWarnings = $this->collectLimitWarnings($chatId, $addedExpenses);
                
                $grouped = $this->groupExpenses($addedExpenses);
                $isForwarded = $update->getMessage()->getForwardDate() !== null;
                $reply = $this->formatExpensesReply($grouped, $isForwarded, $messageDate, $chatId, $limitWarnings);
                $keyboard = $this->buildInlineKeyboard($grouped);
                $this->tg->sendMessage($chatId, $reply, null, false, null, $keyboard);
            }
        } catch (\Throwable $e) {
            $chatId = isset($chatId) ? $chatId : null;
            if ($chatId) {
                $this->tg->sendMessage($chatId, 'Ошибка: ' . $e->getMessage());
            }
        }
    }

    private function buildClassificationPromises(array $items, array $userCategories): array
    {
        $promises = [];
        foreach ($items as $item) {
            $item = trim($item);
            $promises[] = $this->requestClassifierWithRetry($item, $userCategories);
        }
        return $promises;
    }

    /**
     * Отправляет запрос к классификатору с повторными попытками при ошибке соединения.
     *
     * @param string $text
     * @param array $userCategories
     * @param int $attempt
     * @return PromiseInterface
     */
    private function requestClassifierWithRetry(string $text, array $userCategories, int $attempt = 1): PromiseInterface
    {
        $promise = $this->http->postAsync(
            "{$this->classifierUrl}",
            [
                'json' => [
                    'text' => $text,
                    'categories' => $userCategories,
                ],
            ]
        );

        return $promise->then(
            null,
            function ($reason) use ($text, $userCategories, $attempt) {
                if ($reason instanceof ConnectException && $attempt < 3) {
                    sleep(30);

                    return $this->requestClassifierWithRetry($text, $userCategories, $attempt + 1);
                }

                throw $reason;
            }
        );
    }

    private function classifyAndAddExpense($chatId, $it, $date)
    {
        $expenseId = $this->expenseModel->add(
            $chatId,
            $it['name'],
            $it['category'],
            (float)$it['price'],
            $date
        );
        $limit = $this->limitModel->get($chatId, $it['category']);
        return [
            'id'       => $expenseId,
            'category' => $it['category'],
            'name'     => mb_substr($it['name'], 0, 15),
            'price'    => $it['price'],
            'limit'    => $limit
        ];
    }

    /**
     * Собирает все предупреждения о лимитах для добавленных расходов
     *
     * @param int $chatId
     * @param array $addedExpenses
     * @return array Массив строк с предупреждениями
     */
    private function collectLimitWarnings(int $chatId, array $addedExpenses): array
    {
        $warnings = [];
        $checkedCategories = [];
        
        // Проверяем лимиты по категориям
        foreach ($addedExpenses as $expense) {
            $category = $expense['category'];
            
            // Пропускаем уже проверенные категории
            if (in_array($category, $checkedCategories)) {
                continue;
            }
            $checkedCategories[] = $category;
            
            $limit = $expense['limit'];
            if ($limit !== null) {
                $total = $this->expenseModel->getMonthlyTotal($chatId, $category);
                if ($total >= $limit) {
                    $warnings[] = "🔴 Превышение «{$category}»: {$total} / {$limit}";
                } else if ($total >= $limit * 0.8) {
                    $warnings[] = "🟡 Внимание «{$category}»: {$total} / {$limit}";
                }
            }
        }
        
        // Проверяем общий лимит
        $globalLimit = $this->limitModel->getGlobal($chatId);
        if ($globalLimit !== null) {
            $totalExpenses = $this->expenseModel->getTotalLast30Days($chatId);
            if ($totalExpenses >= $globalLimit) {
                $warnings[] = "🔴 Превышение общего лимита: {$totalExpenses} / {$globalLimit}";
            } else if ($totalExpenses >= $globalLimit * 0.8) {
                $warnings[] = "🟡 Внимание общий лимит: {$totalExpenses} / {$globalLimit}";
            }
        }
        
        return $warnings;
    }

    private function groupExpenses(array $expenses): array
    {
        $grouped = [];
        foreach ($expenses as $ex) {
            $cat = $ex['category'];
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'sum'   => 0,
                    'limit' => $ex['limit'],
                    'items' => []
                ];
            }
            $grouped[$cat]['sum'] += (float)$ex['price'];
            $grouped[$cat]['items'][] = [
                'id'    => $ex['id'],
                'name'  => $ex['name'],
                'price' => $ex['price']
            ];
        }
        return $grouped;
    }

    private function formatExpensesReply(array $grouped, bool $isForwarded, $messageDate, int $chatId, array $limitWarnings = []): string
    {
        $lines = [];
        foreach ($grouped as $cat => $info) {
            // Общая сумма за последние 30 дней из базы
            $total = $this->expenseModel->getMonthlyTotal($chatId, $cat);
            // Формируем строку категории с указанием лимита
            $line = "• {$cat}: {$total}";
            if ($info['limit'] !== null) {
                $line .= " / {$info['limit']}";
            }
            $lines[] = $line;
            // Список добавленных трат в этом сообщении
            foreach ($info['items'] as $ex) {
                $lines[] = "    - {$ex['name']} — {$ex['price']}";
            }
        }
        
        // Добавляем общую сумму с лимитом (если установлен)
        $globalLimit = $this->limitModel->getGlobal($chatId);
        $totalExpenses = $this->expenseModel->getTotalLast30Days($chatId);
        $globalLine = "\nВсего: {$totalExpenses}";
        if ($globalLimit !== null) {
            $globalLine .= " / {$globalLimit}";
        }
        $lines[] = $globalLine;
        
        // Добавляем предупреждения о лимитах
        if (!empty($limitWarnings)) {
            $lines[] = '';
            foreach ($limitWarnings as $warning) {
                $lines[] = $warning;
            }
        }
        
        if ($isForwarded && $messageDate) {
            $lines[] = '⏰ Дата: ' . date('d.m.y H:i', $messageDate);
        }
        return implode("\n", $lines);
    }

    /**
     * Определяет дату оригинального сообщения (для пересланных сообщений)
     *
     * @param Update $update
     * @return int Unix timestamp
     */
    private function getOriginalMessageDate(Update $update): int
    {
        $message = $update->getMessage();
        
        // Проверяем, является ли сообщение пересланным
        if ($message->getForwardDate() !== null) {
            // Возвращаем дату оригинального сообщения
            return $message->getForwardDate();
        }
        
        // Если не переслано, возвращаем дату получения
        return $message->getDate();
    }

    /**
     * Создает inline клавиатуру с кнопками для смены категории
     *
     * @param array $grouped Сгруппированные расходы
     * @return \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup
     */
    private function buildInlineKeyboard(array $grouped): \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup
    {
        $buttons = [];
        
        foreach ($grouped as $cat => $info) {
            foreach ($info['items'] as $item) {
                $buttons[] = [
                    [
                        'text' => "✏️ {$item['name']} ({$item['price']}) — сменить категорию",
                        'callback_data' => "change_cat:{$item['id']}"
                    ]
                ];
            }
        }
        
        return new \TelegramBot\Api\Types\Inline\InlineKeyboardMarkup($buttons);
    }

}
