<?php

namespace App\Tests\Unit;

use App\Services\GeminiClassifier;
use App\Services\GeminiUnavailable;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Классификатор решает не только «какая категория», но и «повторять ли попытку» —
 * а на этом решении держится вся очередь: GeminiUnavailable уводит сообщение
 * в `expenses.retry`, любой другой исход подтверждает его навсегда.
 *
 * Спутать эти две ветки дорого в обе стороны: неверный ключ, принятый за временный
 * сбой, крутит сообщение пять кругов по минуте; временный 429, принятый за
 * окончательный отказ, теряет трату.
 */
class GeminiClassifierTest extends TestCase
{
    private const CATEGORIES = ['Еда', 'Транспорт', 'Развлечения'];

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    // --- Ветка «повторять» ----------------------------------------------------

    public function testTooManyRequestsIsRetryable(): void
    {
        $classifier = $this->classifier([new Response(429)]);

        $this->expectException(GeminiUnavailable::class);
        $classifier->classify(['кофе'], self::CATEGORIES);
    }

    public function testServerErrorIsRetryable(): void
    {
        $classifier = $this->classifier([new Response(503)]);

        $this->expectException(GeminiUnavailable::class);
        $classifier->classify(['кофе'], self::CATEGORIES);
    }

    /** Таймаут и неподнявшаяся сеть — временные по определению. */
    public function testConnectionFailureIsRetryable(): void
    {
        $classifier = $this->classifier([
            new ConnectException('cURL error 28: timeout', new Request('POST', 'https://example.test')),
        ]);

        $this->expectException(GeminiUnavailable::class);
        $classifier->classify(['кофе'], self::CATEGORIES);
    }

    // --- Ветка «не повторять» -------------------------------------------------

    /** Неверный ключ повтором не чинится — пять кругов по минуте ничего не изменят. */
    public function testUnauthorizedIsNotRetryable(): void
    {
        $classifier = $this->classifier([new Response(401)]);

        $this->assertSame([], $classifier->classify(['кофе'], self::CATEGORIES));
    }

    public function testBadRequestIsNotRetryable(): void
    {
        $classifier = $this->classifier([new Response(400)]);

        $this->assertSame([], $classifier->classify(['кофе'], self::CATEGORIES));
    }

    // --- Разбор ответа --------------------------------------------------------

    public function testMapsNamesToCategoriesInOrder(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда', 'Транспорт'])]);

        $result = $classifier->classify(['кофе', 'такси'], self::CATEGORIES);

        $this->assertSame(['кофе' => 'Еда', 'такси' => 'Транспорт'], $result);
    }

    /**
     * Соответствие «название → категория» держится исключительно на порядке.
     * Если меток пришло другое количество, сопоставить их не с чем, и разложить
     * траты наугад хуже, чем не разложить вовсе.
     */
    public function testLabelCountMismatchDiscardsWholeAnswer(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда'])]);

        $this->assertSame([], $classifier->classify(['кофе', 'такси'], self::CATEGORIES));
    }

    /** responseSchema ограничивает ответ enum'ом, но чужая категория в общем словаре дороже проверки. */
    public function testDropsLabelOutsideAllowedCategories(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда', 'Криптовалюта'])]);

        $result = $classifier->classify(['кофе', 'биткоин'], self::CATEGORIES);

        $this->assertSame(['кофе' => 'Еда'], $result);
    }

    public function testMalformedJsonBodyGivesEmptyResult(): void
    {
        $classifier = $this->classifier([new Response(200, [], 'не json вовсе')]);

        $this->assertSame([], $classifier->classify(['кофе'], self::CATEGORIES));
    }

    public function testResponseWithoutTextPartGivesEmptyResult(): void
    {
        $classifier = $this->classifier([
            new Response(200, [], json_encode(['candidates' => [['finishReason' => 'SAFETY']]])),
        ]);

        $this->assertSame([], $classifier->classify(['кофе'], self::CATEGORIES));
    }

    public function testTextPartWithNonArrayJsonGivesEmptyResult(): void
    {
        $classifier = $this->classifier([new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => '"просто строка"']]]]],
        ]))]);

        $this->assertSame([], $classifier->classify(['кофе'], self::CATEGORIES));
    }

    // --- Экономия запросов ----------------------------------------------------

    public function testDoesNotCallApiWithoutNames(): void
    {
        $classifier = $this->classifier([]);

        $this->assertSame([], $classifier->classify([], self::CATEGORIES));
        $this->assertSame([], $this->history, 'Пустой список не должен стоить запроса');
    }

    public function testDoesNotCallApiWithoutCategories(): void
    {
        $classifier = $this->classifier([]);

        $this->assertSame([], $classifier->classify(['кофе'], []));
        $this->assertSame([], $this->history);
    }

    /** Одинаковые позиции в одном сообщении — один пункт в запросе, а не два. */
    public function testDeduplicatesNames(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда'])]);

        $result = $classifier->classify(['кофе', 'кофе'], self::CATEGORIES);

        $this->assertSame(['кофе' => 'Еда'], $result);
        $this->assertSame(1, $this->payload()['generationConfig']['responseSchema']['maxItems']);
    }

    /** Всё сообщение уезжает одним запросом, а не по запросу на позицию. */
    public function testSendsSingleRequestForAllItems(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда', 'Транспорт', 'Развлечения'])]);

        $classifier->classify(['кофе', 'такси', 'кино'], self::CATEGORIES);

        $this->assertCount(1, $this->history);
    }

    // --- Форма запроса --------------------------------------------------------

    /**
     * Схема ответа — то, чем гарантируется разбираемость: enum по категориям
     * пользователя и ровно по метке на позицию.
     */
    public function testPinsResponseSchemaToUserCategories(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда', 'Транспорт'])]);

        $classifier->classify(['кофе', 'такси'], self::CATEGORIES);

        $schema = $this->payload()['generationConfig']['responseSchema'];

        $this->assertSame(self::CATEGORIES, $schema['items']['enum']);
        $this->assertSame(2, $schema['minItems']);
        $this->assertSame(2, $schema['maxItems']);
        $this->assertSame(0, $this->payload()['generationConfig']['temperature']);
    }

    public function testSendsApiKeyAsHeaderNotQueryString(): void
    {
        $classifier = $this->classifier([$this->reply(['Еда'])], 'secret-key');

        $classifier->classify(['кофе'], self::CATEGORIES);

        $request = $this->history[0]['request'];

        $this->assertSame('secret-key', $request->getHeaderLine('x-goog-api-key'));
        $this->assertStringNotContainsString('secret-key', (string)$request->getUri());
    }

    // --- Инфраструктура теста -------------------------------------------------

    /**
     * @param array<int, mixed> $responses Очередь ответов для MockHandler
     */
    private function classifier(array $responses, string $apiKey = 'test-key'): GeminiClassifier
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GeminiClassifier(new HttpClient(['handler' => $stack]), $apiKey);
    }

    /**
     * Ответ Gemini в его настоящей форме: JSON-массив меток лежит строкой
     * внутри candidates[0].content.parts[0].text.
     *
     * @param string[] $labels
     */
    private function reply(array $labels): Response
    {
        return new Response(200, [], json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($labels)]]]]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Тело последнего ушедшего запроса.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $request = $this->history[array_key_last($this->history)]['request'];

        return json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
