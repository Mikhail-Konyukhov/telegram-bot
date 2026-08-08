<?php

namespace App;

/**
 * Класс для работы с конфигурационным файлом config.txt
 *
 * Локально значения пишет контейнер `init` в `php/config.txt`. В проде файла нет:
 * домен постоянный, а секреты приезжают в окружение контейнера из `.env`, поэтому
 * при отсутствии файла значения берутся из переменных среды. Это заодно избавляет
 * прод от общего тома между `php` и `init` только ради одного файла.
 */
class Cfg
{
    private array $data;
    private string $configPath;

    /**
     * Cfg constructor.
     * @param string|null $path Путь к config.txt. Если null — `/var/www/html/config.txt`,
     *                          а при его отсутствии значения читаются из окружения.
     * @throws \RuntimeException
     */
    public function __construct(?string $path = null)
    {
        $this->configPath = $path ?? '/var/www/html/config.txt';

        if (!is_readable($this->configPath)) {
            // Явно указанный путь обязан существовать — иначе опечатку не заметить.
            if ($path !== null) {
                throw new \RuntimeException("Config file not found or not readable: {$this->configPath}");
            }

            $this->data = [];
            return;
        }

        $data = parse_ini_file($this->configPath, false, INI_SCANNER_TYPED);
        if ($data === false) {
            throw new \RuntimeException("Failed to parse config file: {$this->configPath}");
        }

        $this->data = $data;
    }

    public function getGeminiApiKey(): string
    {
        return $this->get('GEMINI_API_KEY');
    }

    public function getWebAppUrl(): string
    {
        return $this->get('WEBAPP_URL');
    }

    public function getTelegramBotToken(): string
    {
        return $this->get('TELEGRAM_BOT_TOKEN');
    }

    /**
     * Ссылка на direct link Mini App вида `https://t.me/<бот>/<приложение>`.
     *
     * Нужна только для групп: кнопки web_app Telegram там не показывает.
     * Заводится у @BotFather командой /newapp, поэтому значение необязательное —
     * без него бот в группе просто скажет, что приложение не настроено.
     */
    public function getMiniAppLink(): ?string
    {
        $link = $this->data['MINIAPP_LINK'] ?? getenv('MINIAPP_LINK');

        if ($link === false || $link === null || $link === '') {
            return null;
        }

        return rtrim((string)$link, '/');
    }

    private function get(string $key): string
    {
        $value = $this->data[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            throw new \RuntimeException(
                "Config variable '{$key}' is not set in {$this->configPath} nor in environment"
            );
        }

        return (string)$value;
    }
}
