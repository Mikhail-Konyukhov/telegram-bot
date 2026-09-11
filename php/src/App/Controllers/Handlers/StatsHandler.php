<?php

namespace App\Controllers\Handlers;

use App\Services\StatsReport;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\CallbackQuery;
use TelegramBot\Api\Types\Update;

/**
 * Обрабатывает команду /stats и нажатия кнопок дашборда (`d:*`).
 *
 * Хендлер только отправляет то, что собрал {@see StatsReport} — так же, как
 * {@see CallbackHandler} отправляет {@see \App\Services\ExpenseConfirmation}.
 *
 * Команда синхронная: в отличие от траты, здесь нет обращений к Gemini, читать
 * из базы быстро, и очередь только добавила бы задержку.
 */
class StatsHandler
{
    /** Что открывается по /stats и по кнопке из приветствия. */
    public const DEFAULT_SECTION = 'ov';
    public const DEFAULT_PERIOD = 'm';

    private Client $tg;
    private StatsReport $report;

    /**
     * Отчёт — необязательный параметр: прод создаёт его сам, тесты подставляют
     * заглушку, потому что настоящий в конструкторе поднимает соединение с БД.
     */
    public function __construct(Client $tg, ?StatsReport $report = null)
    {
        $this->tg = $tg;
        $this->report = $report ?? new StatsReport();
    }

    /**
     * /stats — первое сообщение с дашбордом.
     *
     * Аргументом можно открыть раздел сразу: `/stats lim`. На этом держится
     * кнопка «Лимиты» в клавиатуре — она шлёт именно такую команду.
     *
     * Книгу трат здесь не заводим: раздел только читает, а по несуществующему
     * чату выборки честно вернут пустоту.
     */
    public function handle(Update $update): void
    {
        $chatId = $update->getMessage()->getChat()->getId();

        $argument = trim(explode(' ', trim((string)$update->getMessage()->getText()), 2)[1] ?? '');
        // Мусор в аргументе StatsReport::render() сам сведёт к «Обзору».
        $section = $argument !== '' ? $argument : self::DEFAULT_SECTION;

        $view = $this->report->render($chatId, $section, self::DEFAULT_PERIOD);

        $this->tg->sendMessage($chatId, $view['text'], 'HTML', false, null, $view['keyboard']);
    }

    /**
     * Нажатие кнопки: перерисовывает то же сообщение, а не шлёт новое —
     * иначе переключение разделов засыпало бы чат.
     */
    public function handleCallback(CallbackQuery $callbackQuery): void
    {
        $callbackId = $callbackQuery->getId();
        $message = $callbackQuery->getMessage();

        // Сообщений старше 48 часов Telegram уже не присылает — редактировать
        // нечего, но ответить на нажатие всё равно нужно.
        if ($message === null) {
            $this->tg->answerCallbackQuery($callbackId, 'Сообщение устарело');
            return;
        }

        // Неизвестные коды StatsReport::render() приводит к значениям по
        // умолчанию сам, поэтому проверять части здесь незачем.
        $parts = explode(':', $callbackQuery->getData());
        $chatId = $message->getChat()->getId();

        $this->tg->answerCallbackQuery($callbackId);

        $view = $this->report->render(
            $chatId,
            $parts[1] ?? self::DEFAULT_SECTION,
            $parts[2] ?? self::DEFAULT_PERIOD,
            // Страница есть только у кнопок листания «Трат»; её отсутствие и
            // мусор в ней одинаково означают первую страницу.
            (int)($parts[3] ?? 0)
        );

        try {
            $this->tg->editMessageText(
                $chatId,
                $message->getMessageId(),
                $view['text'],
                'HTML',
                false,
                $view['keyboard']
            );
        } catch (\Exception $e) {
            // «message is not modified» — повторное нажатие уже открытой кнопки.
            // Для пользователя это не ошибка, как и в CallbackHandler::edit().
        }
    }
}
