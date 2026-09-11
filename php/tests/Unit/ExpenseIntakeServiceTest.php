<?php

namespace App\Tests\Unit;

use App\Models\Category;
use App\Models\CategoryHint;
use App\Models\Expense;
use App\Services\ExpenseIntakeService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Каскад классификации: словарь (бесплатно) → Gemini (платно).
 *
 * Смысл каскада ровно в том, чтобы не платить дважды за одно и то же, поэтому
 * главная проверка здесь — что при попадании в словарь запроса в Gemini
 * не случается вовсе.
 */
class ExpenseIntakeServiceTest extends TestCase
{
    private const USER = 424242;
    private const CATEGORIES = ['Еда', 'Транспорт'];

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    public function testDictionaryHitSkipsGeminiEntirely(): void
    {
        $hints = $this->hints();
        $hints->method('find')->with(self::USER, 'кофе')->willReturn('Еда');

        $service = $this->service($hints, []);

        $result = $service->parse(self::USER, 'кофе 300', self::CATEGORIES);

        $this->assertSame('Еда', $result['items'][0]['category']);
        $this->assertSame('hint', $result['items'][0]['source']);
        $this->assertSame([], $this->history, 'Словарь ответил — платить за модель незачем');
    }

    /**
     * Категорию удалили, но подсказка на неё осталась. Воскрешать её нельзя:
     * трата уехала бы в категорию, которой у пользователя больше нет.
     */
    public function testHintToDeletedCategoryIsIgnored(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturn('Криптовалюта');

        $service = $this->service($hints, [$this->reply(['Еда'])]);

        $result = $service->parse(self::USER, 'кофе 300', self::CATEGORIES);

        $this->assertSame('Еда', $result['items'][0]['category']);
        $this->assertSame('gemini', $result['items'][0]['source']);
        $this->assertCount(1, $this->history, 'Промах словаря должен уйти в модель');
    }

    public function testUnknownItemGoesToGemini(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturn(null);

        $service = $this->service($hints, [$this->reply(['Транспорт'])]);

        $result = $service->parse(self::USER, 'такси 450', self::CATEGORIES);

        $this->assertSame('Транспорт', $result['items'][0]['category']);
        $this->assertSame([], $result['errors']);
    }

    /** В модель уходит только то, чего нет в словаре, — и одним запросом. */
    public function testOnlyUnknownItemsAreSentToGemini(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturnMap([
            [self::USER, 'кофе', 'Еда'],
            [self::USER, 'такси', null],
        ]);

        $service = $this->service($hints, [$this->reply(['Транспорт'])]);

        $result = $service->parse(self::USER, 'кофе 300, такси 450', self::CATEGORIES);

        $this->assertCount(1, $this->history, 'Одно сообщение — один запрос');
        $this->assertStringContainsString('такси', $this->promptText());
        $this->assertStringNotContainsString('кофе', $this->promptText(), 'Известное в модель не уходит');
        $this->assertSame(['Еда', 'Транспорт'], array_column($result['items'], 'category'));
    }

    /**
     * Модель не вернула метку для позиции.
     *
     * Раньше трата выпадала совсем: её не было ни в истории, ни в лимитах, и
     * восстановить её было неоткуда — ровно так 27.08.2026 пропала пачка
     * записей. Теперь она сохраняется без категории, а пользователь проставит
     * её кнопкой в подтверждении.
     */
    public function testItemWithoutGeminiLabelIsKeptUncategorized(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturn(null);

        // Пустой ответ модели: меток нет ни для одной позиции.
        $service = $this->service($hints, [$this->reply([])]);

        $result = $service->parse(self::USER, 'нечто непонятное 100', self::CATEGORIES);

        $this->assertCount(1, $result['items']);
        $this->assertSame(Category::UNCATEGORIZED, $result['items'][0]['category']);
        $this->assertSame(100.0, $result['items'][0]['price']);
        $this->assertSame([], $result['errors'], 'Про это уже говорит «без категории» в подтверждении');
    }

    /**
     * Модель предложила категорию, которой у пользователя нет — метка
     * отбрасывается. Соседняя позиция при этом свою категорию сохраняет.
     */
    public function testOnlyTheUnlabeledItemLosesItsCategory(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturn(null);

        $service = $this->service($hints, [$this->reply(['Еда', 'Криптовалюта'])]);

        $result = $service->parse(self::USER, 'кофе 300, нечто непонятное 100', self::CATEGORIES);

        $this->assertSame(['Еда', Category::UNCATEGORIZED], array_column($result['items'], 'category'));
    }

    /**
     * Пустышка в словаре означала бы, что позиция больше никогда не дойдёт до
     * классификатора: подсказка нашлась — и хватит.
     */
    public function testUncategorizedItemIsNotRememberedInDictionary(): void
    {
        $hints = $this->hints();
        $hints->expects($this->never())->method('remember');

        $expenses = $this->expenses();
        $expenses->method('add')->willReturn(17);

        $service = $this->service($hints, [], $expenses);

        $service->save(self::USER, [
            ['name' => 'психолог', 'price' => 3055.0, 'category' => Category::UNCATEGORIZED],
        ], '2026-08-27 12:00:00');
    }

    /** Ошибки разбора строки доезжают до вызывающего наравне с ошибками модели. */
    public function testParseErrorsArePreserved(): void
    {
        $hints = $this->hints();
        $hints->method('find')->willReturn('Еда');

        $service = $this->service($hints, []);

        $result = $service->parse(self::USER, 'кофе 300, ерунда', self::CATEGORIES);

        $this->assertCount(1, $result['items']);
        $this->assertCount(1, $result['errors']);
    }

    // --- Сохранение ------------------------------------------------------------

    /** Ответ модели попадает в словарь, чтобы второй раз за него не платить. */
    public function testSaveRemembersCategoryInDictionary(): void
    {
        $hints = $this->hints();
        $hints->expects($this->once())->method('remember')->with(self::USER, 'кофе', 'Еда');

        $expenses = $this->expenses();
        $expenses->expects($this->once())
            ->method('add')
            ->with(self::USER, 'кофе', 'Еда', 300.0, '2026-08-14 12:00:00')
            ->willReturn(17);

        $service = $this->service($hints, [], $expenses);

        $saved = $service->save(
            self::USER,
            [['name' => 'кофе', 'price' => 300.0, 'category' => 'Еда', 'source' => 'gemini']],
            '2026-08-14 12:00:00'
        );

        $this->assertSame(17, $saved[0]['id']);
    }

    // --- Инфраструктура теста --------------------------------------------------

    /**
     * @param array<int, mixed> $responses Очередь ответов Gemini
     */
    private function service(
        CategoryHint&MockObject $hints,
        array $responses,
        ?Expense $expenses = null
    ): ExpenseIntakeService {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new ExpenseIntakeService(
            new HttpClient(['handler' => $stack]),
            'test-key',
            $expenses ?? $this->expenses(),
            $hints
        );
    }

    /**
     * @param string[] $labels
     */
    private function reply(array $labels): Response
    {
        return new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($labels)]]]]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Список трат из ушедшего запроса.
     *
     * Именно разобранный, а не сырое тело: Guzzle кодирует JSON без
     * JSON_UNESCAPED_UNICODE, поэтому кириллица уезжает escape-последовательностями
     * вида \uXXXX, и поиск подстроки по сырому телу не нашёл бы ничего.
     */
    private function promptText(): string
    {
        $payload = json_decode(
            (string)$this->history[0]['request']->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $payload['contents'][0]['parts'][0]['text'];
    }

    private function hints(): CategoryHint&MockObject
    {
        return $this->createMock(CategoryHint::class);
    }

    private function expenses(): Expense&MockObject
    {
        return $this->createMock(Expense::class);
    }
}
