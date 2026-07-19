<?php

namespace App;

use PDO;
use PDOException;

/**
 * Класс Database предоставляет доступ к соединению с базой данных (Singleton).
 */
class Database {
    /**
     * Единственный экземпляр класса (Singleton).
     * @var Database|null
     */
    private static $instance = null;

    /**
     * Объект соединения с базой данных.
     * @var PDO
     */
    private $connection;

    /**
     * Приватный конструктор для предотвращения создания экземпляров класса извне.
     * Устанавливает соединение с базой данных.
     */
    private function __construct() {
        $host = 'mysql';
        $dbname = 'telegram_bot';
        $username = 'root';
        $password = 'root';

        try {
            $this->connection = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            die("Ошибка подключения к БД: " . $e->getMessage());
        }
    }

    /**
     * Получает единственный экземпляр класса Database (Singleton).
     * @return Database Экземпляр класса Database.
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Возвращает соединение с базой данных.
     * @return PDO Объект PDO для работы с базой данных.
     */
    public function getConnection(): PDO {
        return $this->connection;
    }
}
