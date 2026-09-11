<?php

namespace App\Services;

use DateTimeImmutable;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Дашборд в виде сообщения Telegram: текст плюс кнопки под ним.
 *
 * Временный дублёр Mini App — те же цифры, что на экране «Обзор», но читаемые
 * прямо в чате. Разделы переключаются правкой того же сообщения, поэтому текст
 * и клавиатура собираются вместе, как в {@see ExpenseConfirmation}.
 *
 * Формат callback_data: `d:<раздел>:<период>[:<страница>]`, например `d:cat:w`
 * или `d:exp:m:2`. Страница есть только у «Трат» — единственного раздела, где
 * строк больше, чем влезает в сообщение. Префикс `d` выбран так, чтобы не
 * пересечься с `u/c/a/s/b` из подтверждения траты.
 *
 * Сообщение уходит с `parse_mode = HTML`: таблицы держатся на моноширинном
 * `<pre>`, а без него пропорциональный шрифт разваливает колонки.
 */
class StatsReport
{
    /** Разделы: код в callback_data => подпись на кнопке. */
    public const SECTIONS = [
        'ov'  => 'Обзор',
        'cat' => 'Категории',
        'exp' => 'Траты',
        'lim' => 'Лимиты',
    ];

    /** Периоды: код => [подпись, длина окна в днях]. */
    public const PERIODS = [
        'w' => ['Неделя', 7],
        'm' => ['Месяц', 30],
        'y' => ['Год', 365],
    ];

    /** Сколько категорий показывать в «Обзоре» — остальные в своём разделе. */
    private const TOP_CATEGORIES = 5;

    /**
     * Сколько трат на страницу раздела «Траты».
     *
     * Не украшение: у сообщения Telegram лимит 4096 символов, и за месяц трат
     * набирается на порядок больше. Остальные страницы доступны кнопками
     * «Раньше»/«Позже» — раньше их просто не было, и всё, кроме последних
     * двадцати, было недостижимо.
     */
    private const PAGE_SIZE = 20;

    /** Ширина колонки с названием в таблицах. */
    private const NAME_WIDTH = 14;

    /** Ширина колонки с суммой. */
    private const AMOUNT_WIDTH = 7;

    private DashboardService $dashboard;
    private LimitsPresenter $limits;

    /**
     * Сервисы — необязательные параметры: прод создаёт их сам, тесты подставляют
     * заглушки, потому что настоящие в конструкторе поднимают соединение с БД.
     */
    public function __construct(?DashboardService $dashboard = null, ?LimitsPresenter $limits = null)
    {
        $this->dashboard = $dashboard ?? new DashboardService();
        $this->limits = $limits ?? new LimitsPresenter();
    }

    /**
     * Собирает раздел дашборда.
     *
     * Неизвестные коды молча откатываются к «Обзору» за месяц: callback_data
     * приходит с клиента и могла остаться от прошлой версии бота.
     *
     * @param int $page Страница раздела «Траты», с нуля; остальные её не знают
     * @return array{text: string, keyboard: InlineKeyboardMarkup}
     */
    public function render(int $chatId, string $section, string $period, int $page = 0): array
    {
        $section = isset(self::SECTIONS[$section]) ? $section : 'ov';
        $period = isset(self::PERIODS[$period]) ? $period : 'm';

        // Число страниц известно только после выборки, поэтому текст собирается
        // первым и возвращает выправленную страницу: запрошенной могло уже не
        // быть — траты удаляют, а кнопка со старым номером остаётся в чате.
        [$text, $page, $pages] = $this->text($chatId, $section, $period, max(0, $page));

        return [
            'text'     => $text,
            'keyboard' => $this->keyboard($section, $period, $page, $pages),
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int} HTML, выправленная страница, всего страниц
     */
    private function text(int $chatId, string $section, string $period, int $page): array
    {
        // «Лимиты» — единственный раздел вне выбранного периода: лимит задан
        // на скользящие 30 дней, и показывать его за неделю значило бы врать.
        if ($section === 'lim') {
            return [$this->limitsSection($chatId), 0, 1];
        }

        [$start, $end] = $this->range($period);
        $expenses = $this->dashboard->getDetailedExpenses($chatId, $start, $end);
        $header = $this->header($section, $start, $end);

        if (!$expenses) {
            return [$header . "\n\nЗа этот период трат нет.", 0, 1];
        }

        // Листается только «Траты»: в остальных разделах строк столько же,
        // сколько категорий, и они помещаются в сообщение целиком.
        if ($section === 'exp') {
            $pages = (int)ceil(count($expenses) / self::PAGE_SIZE);
            $page = min($page, $pages - 1);

            return [$this->expensesSection($header, $expenses, $page), $page, $pages];
        }

        $text = $section === 'cat'
            ? $this->categoriesSection($header, $expenses)
            : $this->overviewSection($header, $chatId, $expenses, $start, $end);

        return [$text, 0, 1];
    }

    /**
     * Итог, сравнение с прошлым периодом, общий лимит и верхушка категорий.
     *
     * @param array $expenses Траты за период
     */
    private function overviewSection(
        string $header,
        int $chatId,
        array $expenses,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): string {
        $total = (float)array_sum(array_column($expenses, 'amount'));
        $count = count($expenses);

        $lines = [
            $header,
            '',
            '<b>' . Format::money($total) . '</b> · ' . $this->plural($count, 'трата', 'траты', 'трат')
                . ' · в среднем ' . Format::money($total / $count),
        ];

        $delta = $this->delta($chatId, $total, $start, $end);
        if ($delta !== null) {
            $lines[] = $delta;
        }

        $global = $this->limits->payload($chatId)['global'];
        if ($global !== null) {
            $lines[] = '';
            $lines[] = '<b>Общий лимит</b>';
            $lines[] = $this->limitLine('', (float)$global['spent'], (float)$global['limit']);
        }

        $lines[] = '';
        $lines[] = '<b>Топ категорий</b>';
        $lines[] = $this->categoryTable($this->byCategory($expenses), self::TOP_CATEGORIES);

        return implode("\n", $lines);
    }

    /**
     * Полная разбивка по категориям.
     *
     * @param array $expenses
     */
    private function categoriesSection(string $header, array $expenses): string
    {
        $total = (float)array_sum(array_column($expenses, 'amount'));
        $byCategory = $this->byCategory($expenses);

        return implode("\n", [
            $header,
            '',
            'Всего <b>' . Format::money($total) . '</b> в '
                . $this->plural(count($byCategory), 'категории', 'категориях', 'категориях'),
            '',
            $this->categoryTable($byCategory, null),
        ]);
    }

    /**
     * Страница списка трат, сгруппированная по дням.
     *
     * Траты идут от новых к старым, поэтому страница 0 — последние дни,
     * а «Раньше» уводит вглубь периода.
     *
     * @param array $expenses Все траты периода
     * @param int $page Номер страницы, с нуля
     */
    private function expensesSection(string $header, array $expenses, int $page): string
    {
        $offset = $page * self::PAGE_SIZE;
        $shown = array_slice($expenses, $offset, self::PAGE_SIZE);

        $lines = [$header, ''];
        $day = null;
        $rows = [];

        foreach ($shown as $expense) {
            $current = $this->dayLabel((string)$expense['ts']);

            if ($current !== $day) {
                if ($rows) {
                    $lines[] = $this->pre($rows);
                    $rows = [];
                }
                $lines[] = '<b>' . $current . '</b>';
                $day = $current;
            }

            $rows[] = Format::pad(Format::truncate((string)$expense['name'], self::NAME_WIDTH), self::NAME_WIDTH)
                . ' ' . Format::pad(Format::money((float)$expense['amount']), self::AMOUNT_WIDTH, true);
        }

        $lines[] = $this->pre($rows);

        if (count($expenses) > self::PAGE_SIZE) {
            $lines[] = '';
            $lines[] = 'Показаны ' . ($offset + 1) . '—' . ($offset + count($shown))
                . ' из ' . count($expenses) . '.';
        }

        return implode("\n", $lines);
    }

    /**
     * Лимиты и расход по ним. Без <pre>: маркер — эмодзи, а он в моноширинном
     * блоке занимает непредсказуемую ширину и ломает ровно то выравнивание,
     * ради которого <pre> и нужен.
     */
    private function limitsSection(int $chatId): string
    {
        $payload = $this->limits->payload($chatId);

        $lines = ['<b>📊 Лимиты</b> · за 30 дней'];

        if ($payload['global'] === null && !$payload['categories']) {
            $lines[] = '';
            $lines[] = 'Лимиты не заданы.';
            $lines[] = '';
            $lines[] = '/setlimit еда 5000 — по категории';
            $lines[] = '/setgloballimit 50000 — общий';

            return implode("\n", $lines);
        }

        if ($payload['global'] !== null) {
            $lines[] = '';
            $lines[] = '<b>Общий</b>';
            $lines[] = $this->limitLine('', (float)$payload['global']['spent'], (float)$payload['global']['limit']);
        }

        if ($payload['categories']) {
            $lines[] = '';
            $lines[] = '<b>По категориям</b>';

            foreach ($payload['categories'] as $item) {
                $lines[] = $this->limitLine(
                    (string)$item['category'],
                    (float)$item['spent'],
                    (float)$item['limit']
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Строка лимита: маркер, расход, остаток или перерасход.
     *
     * @param string $category Пустая строка — общий лимит, ему подпись не нужна
     */
    private function limitLine(string $category, float $spent, float $limit): string
    {
        $left = $limit - $spent;
        $tail = $left >= 0
            ? 'осталось ' . Format::money($left)
            : 'перерасход ' . Format::money(-$left);

        return Format::marker($spent, $limit)
            . ($category !== '' ? ' ' . $this->escape($category) . ' ·' : '')
            . ' ' . Format::money($spent) . ' / ' . Format::money($limit)
            . ' · ' . $tail;
    }

    /**
     * Таблица «категория — сумма — полоса».
     *
     * Полоса меряется от самой крупной категории, а не от общей суммы: при
     * дюжине категорий доля каждой мала, и все полосы вышли бы одинаково
     * пустыми. Точные величины стоят рядом, так что полоса нужна только для
     * сравнения строк между собой.
     *
     * @param array<string, float> $byCategory Уже отсортировано по убыванию
     * @param int|null $limit Сколько строк показать; null — все
     */
    private function categoryTable(array $byCategory, ?int $limit): string
    {
        $rows = [];
        $max = $byCategory ? max($byCategory) : 0.0;

        foreach ($byCategory as $category => $amount) {
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }

            $rows[] = Format::pad(Format::truncate((string)$category, self::NAME_WIDTH), self::NAME_WIDTH)
                . ' ' . Format::pad(Format::money($amount), self::AMOUNT_WIDTH, true)
                . ' ' . Format::bar($max > 0 ? $amount / $max : 0.0);
        }

        return $this->pre($rows);
    }

    /**
     * Сравнение с прошлым периодом. null, если сравнивать не с чем —
     * «+100%» от нуля не значит ничего.
     */
    private function delta(int $chatId, float $total, DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        [$prevStart, $prevEnd] = $this->dashboard->previousPeriod($start, $end);

        $previous = (float)array_sum(array_column(
            $this->dashboard->getDetailedExpenses($chatId, $prevStart, $prevEnd),
            'amount'
        ));

        if ($previous <= 0) {
            return null;
        }

        $change = (int)round((($total - $previous) / $previous) * 100);

        return ($change > 0 ? '↑ ' : '↓ ') . abs($change) . '% к прошлому периоду';
    }

    /**
     * Суммы по категориям, по убыванию.
     *
     * @param array $expenses
     * @return array<string, float>
     */
    private function byCategory(array $expenses): array
    {
        $byCategory = [];

        foreach ($expenses as $expense) {
            $category = (string)$expense['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0.0) + (float)$expense['amount'];
        }

        arsort($byCategory);

        return $byCategory;
    }

    private function header(string $section, DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        // Год нужен, только когда период пересекает его границу: «Год» без него
        // выглядел как «01.09 — 31.08», то есть как перевёрнутый диапазон.
        $format = $start->format('Y') === $end->format('Y') ? 'd.m' : 'd.m.Y';

        return '<b>📊 ' . self::SECTIONS[$section] . '</b> · '
            . $start->format($format) . ' — ' . $end->format($format);
    }

    /**
     * Скользящее окно до сегодня — так же считает presetRange() в Mini App,
     * и это совпадает с «За 30 дней» в подтверждении траты.
     *
     * @return DateTimeImmutable[] [$start, $end]
     */
    private function range(string $period): array
    {
        $days = self::PERIODS[$period][1];

        $end = new DateTimeImmutable('today 23:59:59');
        $start = $end->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);

        return [$start, $end];
    }

    /** «Сегодня», «Вчера» или «24.08» — месяцы словами требуют intl. */
    private function dayLabel(string $ts): string
    {
        $date = date('Y-m-d', strtotime($ts));

        return match ($date) {
            date('Y-m-d') => 'Сегодня',
            date('Y-m-d', strtotime('-1 day')) => 'Вчера',
            default => date('d.m', strtotime($ts)),
        };
    }

    /**
     * @param string[] $rows
     */
    private function pre(array $rows): string
    {
        return '<pre>' . $this->escape(implode("\n", $rows)) . '</pre>';
    }

    /**
     * Названия категорий и трат вводит пользователь, а сообщение уходит с
     * parse_mode = HTML: незакрытый «<» уронил бы отправку целиком.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        $word = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };

        return $count . ' ' . $word;
    }

    /**
     * Активный пункт помечен точками: у инлайн-кнопок Telegram нет состояния
     * «нажато», и без метки непонятно, что открыто.
     */
    private function keyboard(string $section, string $period, int $page, int $pages): InlineKeyboardMarkup
    {
        $sections = [];
        foreach (self::SECTIONS as $id => $label) {
            // Номер страницы не переносится: смена раздела или периода меняет
            // и сам список, и страница из прошлого набора значила бы не то.
            $sections[] = [
                'text'          => $id === $section ? "· {$label} ·" : $label,
                'callback_data' => "d:{$id}:{$period}",
            ];
        }

        $periods = [];
        foreach (self::PERIODS as $id => [$label]) {
            $periods[] = [
                'text'          => $id === $period ? "· {$label} ·" : $label,
                'callback_data' => "d:{$section}:{$id}",
            ];
        }

        $rows = [
            array_slice($sections, 0, 2),
            array_slice($sections, 2, 2),
            $periods,
        ];

        $nav = $this->pageButtons($section, $period, $page, $pages);
        if ($nav) {
            $rows[] = $nav;
        }

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Кнопки листания. Пустой массив — листать нечего, ряда не будет.
     *
     * Положение показано текстом внизу сообщения, а не третьей кнопкой между
     * стрелками: у такой кнопки нет осмысленного действия, а нажатие на неё
     * Telegram всё равно потребует чем-то ответить.
     *
     * @return array<int, array{text: string, callback_data: string}>
     */
    private function pageButtons(string $section, string $period, int $page, int $pages): array
    {
        if ($pages < 2) {
            return [];
        }

        $buttons = [];

        // Список идёт от новых к старым: «Раньше» — вглубь, значит вперёд.
        if ($page + 1 < $pages) {
            $buttons[] = ['text' => '← Раньше', 'callback_data' => "d:{$section}:{$period}:" . ($page + 1)];
        }

        if ($page > 0) {
            $buttons[] = ['text' => 'Позже →', 'callback_data' => "d:{$section}:{$period}:" . ($page - 1)];
        }

        return $buttons;
    }
}
