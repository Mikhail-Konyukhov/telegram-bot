<?php

namespace App\Controllers\Handlers;

use App\Models\Category;
use App\Models\User;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команды для управления категориями
 */
class CategoryHandler
{
    private Client $tg;
    private Category $categoryModel;
    private User $userModel;

    public function __construct(Client $tg)
    {
        $this->tg = $tg;
        $this->categoryModel = new Category();
        $this->userModel = new User();
    }

    /**
     * Обрабатывает команды категорий
     *
     * @param Update $update
     * @return void
     */
    public function handle(Update $update): void
    {
        $msgText = trim($update->getMessage()->getText());
        $chatId = $update->getMessage()->getChat()->getId();

        // Заводим книгу трат, если её ещё нет (в группе владелец — сам чат)
        $this->userModel->ensure($chatId);

        // Парсим команду
        $parts = explode(' ', $msgText, 3);
        $command = $parts[0];
        $action = $parts[1] ?? '';
        $categoryName = $parts[2] ?? '';

        switch ($action) {
            case 'список':
            case 'list':
                $this->listCategories($chatId);
                break;

            case 'добавить':
            case 'add':
                if (empty($categoryName)) {
                    $this->tg->sendMessage($chatId, 
                        "❌ Укажите название категории:\n" .
                        "/categories add название_категории"
                    );
                    return;
                }
                $this->addCategory($chatId, $categoryName);
                break;

            case 'удалить':
            case 'delete':
                if (empty($categoryName)) {
                    $this->tg->sendMessage($chatId, 
                        "❌ Укажите название категории для удаления:\n" .
                        "/categories delete название_категории"
                    );
                    return;
                }
                $this->deleteCategory($chatId, $categoryName);
                break;

            default:
                $this->showHelp($chatId);
                break;
        }
    }

    /**
     * Показывает список всех категорий пользователя
     *
     * @param int $chatId
     * @return void
     */
    private function listCategories(int $chatId): void
    {
        $categories = $this->categoryModel->getUserCategories($chatId);
        $personalCategories = $this->categoryModel->getUserPersonalCategories($chatId);

        if (empty($categories)) {
            $this->tg->sendMessage($chatId, "🤷‍♂️ У вас нет доступных категорий.");
            return;
        }

        $message = "📋 **Ваши категории:**\n\n";
        
        // Системные категории
        $defaultCategories = $this->categoryModel->getDefaultCategories();
        if (!empty($defaultCategories)) {
            $message .= "🔧 **Системные категории:**\n";
            foreach ($defaultCategories as $category) {
                $message .= "• " . $category . "\n";
            }
            $message .= "\n";
        }

        // Персональные категории
        if (!empty($personalCategories)) {
            $message .= "👤 **Ваши персональные категории:**\n";
            foreach ($personalCategories as $category) {
                $message .= "• " . $category['name'] . "\n";
            }
            $message .= "\n";
        }

        $message .= "ℹ️ **Команды:**\n";
        $message .= "/categories add название - добавить категорию\n";
        $message .= "/categories delete название - удалить категорию";

        $this->tg->sendMessage($chatId, $message);
    }

    /**
     * Добавляет новую персональную категорию
     *
     * @param int $chatId
     * @param string $categoryName
     * @return void
     */
    private function addCategory(int $chatId, string $categoryName): void
    {
        // Проверяем, не существует ли уже такая категория
        if ($this->categoryModel->categoryExists($chatId, $categoryName)) {
            $this->tg->sendMessage($chatId, 
                "❌ Категория \"$categoryName\" уже существует."
            );
            return;
        }

        // Добавляем категорию
        if ($this->categoryModel->addUserCategory($chatId, $categoryName)) {
            $this->tg->sendMessage($chatId, 
                "✅ Категория \"$categoryName\" успешно создана!"
            );
        } else {
            $this->tg->sendMessage($chatId, 
                "❌ Не удалось создать категорию \"$categoryName\". Возможно, она уже существует."
            );
        }
    }

    /**
     * Удаляет персональную категорию
     *
     * @param int $chatId
     * @param string $categoryName
     * @return void
     */
    private function deleteCategory(int $chatId, string $categoryName): void
    {
        if ($this->categoryModel->deleteUserCategory($chatId, $categoryName)) {
            $this->tg->sendMessage($chatId, 
                "✅ Персональная категория \"$categoryName\" удалена!"
            );
        } else {
            $this->tg->sendMessage($chatId, 
                "❌ Не удалось удалить категорию \"$categoryName\". " .
                "Возможно, она не существует или является системной."
            );
        }
    }

    /**
     * Показывает справку по командам категорий
     *
     * @param int $chatId
     * @return void
     */
    private function showHelp(int $chatId): void
    {
        $message = "📋 **Управление категориями**\n\n";
        $message .= "**Доступные команды:**\n";
        $message .= "/categories list - показать все категории\n";
        $message .= "/categories add название - добавить новую категорию\n";
        $message .= "/categories delete название - удалить персональную категорию\n\n";
        $message .= "**Примеры:**\n";
        $message .= "/categories add образование\n";
        $message .= "/categories delete образование\n\n";
        $message .= "ℹ️ Системные категории удалить нельзя, но вы можете добавлять свои персональные.";

        $this->tg->sendMessage($chatId, $message);
    }
} 