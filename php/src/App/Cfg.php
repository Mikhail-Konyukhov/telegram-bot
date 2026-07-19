<?php

namespace App;

/**
 * Класс для работы с конфигурационным файлом config.txt
 */
class Cfg
{
    private array $data;
    private string $configPath;

    /**
     * Cfg constructor.
     * @param string|null $path Путь к config.txt. Если null, будет использоваться путь в /var/www/html/config.txt
     * @throws \RuntimeException
     */
    public function __construct(?string $path = null)
    {
        if ($path !== null) {
            $this->configPath = $path;
        } else {
            // Предполагаем, что config.txt лежит в корне веб-приложения
            $this->configPath = '/var/www/html/config.txt';
        }

        if (!is_readable($this->configPath)) {
            throw new \RuntimeException("Config file not found or not readable: {$this->configPath}");
        }

        $this->data = parse_ini_file($this->configPath, false, INI_SCANNER_TYPED);
        if ($this->data === false) {
            throw new \RuntimeException("Failed to parse config file: {$this->configPath}");
        }
    }

    public function getClassifierUrl(): string
    {
        return $this->get('CLASSIFIER_URL');
    }

    public function getDashboardUrl(): string
    {
        return $this->get('DASHBOARD_URL');
    }

    public function getTelegramBotToken(): string
    {
        return $this->get('TELEGRAM_BOT_TOKEN');
    }

    private function get(string $key): string
    {
        if (!isset($this->data[$key]) || $this->data[$key] === '') {
            throw new \RuntimeException("Config variable '{$key}' is not set in {$this->configPath}");
        }
        return (string)$this->data[$key];
    }
}
