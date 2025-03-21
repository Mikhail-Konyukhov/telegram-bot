<?php

namespace App;

use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Класс Bot отвечает за обработку сообщений от Telegram-бота.
 */
class Bot
{
    /**
     * @var Client $bot Экземпляр Telegram-бота.
     */
    private Client $bot;

    /**
     * @var User $userModel Модель для работы с пользователями.
     */
    private User $userModel;

    /**
     * Конструктор класса.
     * Создает объект бота и инициализирует модель пользователя.
     */
    public function __construct()
    {
        // Чтение токена из файла config.txt
        $config = parse_ini_file('config.txt');
        $botToken = $config['TELEGRAM_BOT_TOKEN'] ?? '';

        if (!$botToken) {
            die('Ошибка: токен не найден в конфигурационном файле.');
        }

        $this->bot = new Client($botToken);
        $this->userModel = new User();
    }

    /**
     * Обрабатывает входящее обновление от Telegram.
     *
     * @param Update $update Объект обновления Telegram.
     * @return void
     */
    public function handleUpdate(Update $update): void
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $messageText = trim($message->getText());
        $response = "";

        if (!$messageText) {
            return;
        }

        // Регистрация пользователя, если не существует с таким ID
        if (!$this->userModel->isExists($chatId)) {
            $this->userModel->registerUser($chatId);
            $response = "Вы зарегистрированы! Ваш баланс: $0.00\n";
        }

        // Если сообщение является числом, обрабатываем транзакцию
        if ($this->isValidNumber($messageText)) {
            $response = $this->userModel->updateBalance($chatId, $messageText);
            $this->bot->sendMessage($chatId, $response);
            return;
        }

        // Если сообщение не является числом, отправка сообщения пользователю
        $response = $response . "Введите число для пополнения или списания.";
        $this->bot->sendMessage($chatId, $response);
    }

    /**
     * Проверяет, является ли переданный текст числом (с точкой или запятой).
     *
     * @param string $text Входная строка из сообщения пользователя.
     * @return bool Возвращает true, если строка является числом, иначе false.
     */
    private function isValidNumber(string $text): bool
    {
        // Обновленное регулярное выражение для поддержки числа с плюсом
        return preg_match('/^[+-]?\d+([.,]\d+)?$/', $text);
    }
}
