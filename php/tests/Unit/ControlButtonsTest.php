<?php

namespace App\Tests\Unit;

use App\Controllers\Handlers\StartHandler;
use App\Controllers\Handlers\StatsHandler;
use App\Services\StatsReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\Update;

/**
 * Ряд управления в клавиатуре бота.
 *
 * Нажатие такой кнопки присылает обычный текст, и Bot::handleUpdate подменяет
 * его на команду. Отсюда два тихих способа всё сломать: подпись, похожая на
 * трату (тогда она уедет в разбор расходов и запишется как покупка), и
 * команда, под которую в роутере нет ветки — кнопка молча провалится в тот же
 * разбор трат.
 */
class ControlButtonsTest extends TestCase
{
    /** Префиксы, которые Bot::handleUpdate разбирает как команды. */
    private const ROUTED = ['/start', '/help', '/app', '/dashboard', '/web', '/stats', '/setgloballimit', '/setlimit', '/categories'];

    #[DataProvider('provideControls')]
    public function testEveryButtonMapsToARoutedCommand(string $label, string $command): void
    {
        $prefix = explode(' ', $command)[0];

        $this->assertContains($prefix, self::ROUTED, "Кнопка «{$label}» ведёт в никуда");
    }

    /**
     * Подпись обязана быть непохожей на трату: ExpenseTextParser считает тратой
     * всё, где есть число, и «Лимиты 5000» записалось бы как покупка.
     */
    #[DataProvider('provideControls')]
    public function testButtonLabelHasNoDigits(string $label, string $command): void
    {
        $this->assertDoesNotMatchRegularExpression('/\d/', $label, "«{$label}» разберётся как трата");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideControls(): iterable
    {
        foreach (StartHandler::CONTROLS as $label => $command) {
            yield $label => [$label, $command];
        }
    }

    /**
     * Клавиатура обязана сворачиваться.
     *
     * С `is_persistent` Telegram не даёт её убрать, и системная кнопка «назад»
     * на Android переставала работать в чате с ботом: свернуть было нечего,
     * а до выхода из диалога дело не доходило.
     *
     * Конструктор StartHandler поднимает соединение с БД, поэтому клавиатура
     * берётся напрямую у объекта без конструктора — она ни от чего не зависит.
     */
    public function testKeyboardIsCollapsible(): void
    {
        $handler = (new \ReflectionClass(StartHandler::class))->newInstanceWithoutConstructor();
        $keyboard = (new \ReflectionMethod(StartHandler::class, 'keyboard'))->invoke($handler);

        $this->assertNotTrue($keyboard->getIsPersistent(), 'Иначе «назад» на Android ничего не делает');
        $this->assertTrue($keyboard->isResizeKeyboard(), 'Иначе клавиатура занимает пол-экрана');
    }

    /** Кнопки различимы: одинаковые подписи Telegram покажет, а бот перепутает. */
    public function testLabelsAreUnique(): void
    {
        $labels = array_keys(StartHandler::CONTROLS);

        $this->assertSame($labels, array_unique($labels));
    }

    // --- Аргумент /stats -------------------------------------------------------

    /** Кнопка «Лимиты» шлёт `/stats lim` — раздел должен открыться сразу. */
    public function testStatsCommandOpensRequestedSection(): void
    {
        $report = $this->createMock(StatsReport::class);
        $report->expects($this->once())
            ->method('render')
            ->with(42, 'lim', StatsHandler::DEFAULT_PERIOD)
            ->willReturn(['text' => 'лимиты', 'keyboard' => null]);

        (new StatsHandler($this->createMock(Client::class), $report))->handle($this->update('/stats lim'));
    }

    public function testBareStatsOpensOverview(): void
    {
        $report = $this->createMock(StatsReport::class);
        $report->expects($this->once())
            ->method('render')
            ->with(42, StatsHandler::DEFAULT_SECTION, StatsHandler::DEFAULT_PERIOD)
            ->willReturn(['text' => 'обзор', 'keyboard' => null]);

        (new StatsHandler($this->createMock(Client::class), $report))->handle($this->update('/stats'));
    }

    /** Кнопка «Лимиты» из CONTROLS обязана вести именно в раздел лимитов. */
    public function testLimitsButtonSendsLimitsSection(): void
    {
        $command = StartHandler::CONTROLS['🎯 Лимиты'];

        $this->assertSame('/stats lim', $command);
        $this->assertArrayHasKey('lim', StatsReport::SECTIONS);
    }

    private function update(string $text): Update
    {
        return Update::fromResponse([
            'update_id' => 1,
            'message'   => [
                'message_id' => 1,
                'date'       => time(),
                'chat'       => ['id' => 42, 'type' => 'private'],
                'text'       => $text,
            ],
        ]);
    }
}
