<?php

namespace App\Tests\E2E;

use App\Cfg;
use App\Models\CategoryHint;
use App\Services\WebSession;
use App\Tests\Support\InitDataFactory;
use App\Tests\Support\IntegrationTestCase;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface;

/**
 * Контракт api.php целиком, через настоящий HTTP.
 *
 * Тесты характеризующие: они записывают то, как API отвечает СЕЙЧАС, и нужны
 * как страховка под разбиение ExpenseApiController. Пока они зелёные, снаружи
 * ничего не изменилось — а Mini App читает именно это.
 *
 * Запросы идут не в контейнерный сервер на 8081, а в собственный, поднятый этим
 * же тестом с DB_NAME=telegram_bot_test. Иначе прогон писал бы траты и категории
 * в рабочую базу.
 */
class ApiContractTest extends IntegrationTestCase
{
    /** Порт для тестового сервера — лишь бы не совпал с рабочим 80. */
    private const PORT = 8099;

    private const USER = InitDataFactory::USER_ID;

    /** @var resource|null */
    private static $server = null;

    private HttpClient $http;
    private string $botToken;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->botToken = (new Cfg())->getTelegramBotToken();
        $this->http = new HttpClient([
            'base_uri' => 'http://127.0.0.1:' . self::PORT . '/',
            'timeout' => 10,
            // Коды ответа — часть проверяемого контракта, а не повод для исключения.
            'http_errors' => false,
        ]);
    }

    // --- Доступ ----------------------------------------------------------------

    /**
     * Заголовок с подписью — единственное, что отделяет данные от кого угодно.
     * Проверяется на каждом действии: забытая проверка в одной ветке роутера
     * открыла бы её целиком.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideReadActions')]
    public function testRequestWithoutInitDataIsForbidden(string $action): void
    {
        $response = $this->http->get('api.php?action=' . $action);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReadActions(): iterable
    {
        yield 'overview' => ['overview'];
        yield 'expenses' => ['expenses'];
        yield 'categories' => ['categories'];
        yield 'limits' => ['limits'];
        yield 'suggestions' => ['suggestions'];
        yield 'analytics_by_period' => ['analytics_by_period'];
    }

    public function testTamperedSignatureIsForbidden(): void
    {
        $initData = InitDataFactory::create([], $this->botToken);

        $response = $this->http->get('api.php?action=overview', [
            'headers' => ['X-Telegram-Init-Data' => $initData . 'мусор'],
        ]);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Веб-версия ------------------------------------------------------------

    /**
     * Браузер вне Telegram ходит в тот же API и без initData: его пускает
     * подписанная cookie, которую ставит /web.
     */
    public function testWebSessionCookieAuthenticates(): void
    {
        $this->seedExpense('кофе', 'еда', 300.0);

        $response = $this->withCookie(WebSession::cookie(self::USER, $this->botToken));

        $this->assertSame(300, $this->successData($response)['total']);
    }

    /** Формат сохранён, подпись — нет: проверяется именно она, а не разбор строки. */
    public function testTamperedWebSessionCookieIsForbidden(): void
    {
        $cookie = WebSession::cookie(self::USER, $this->botToken);
        $forged = substr($cookie, 0, -1) . (str_ends_with($cookie, 'a') ? 'b' : 'a');

        $this->assertSame(403, $this->withCookie($forged)->getStatusCode());
    }

    /** Ссылка из бота меняется на cookie и уходит из адреса. */
    public function testWebLinkSetsSessionCookieAndRedirects(): void
    {
        $link = WebSession::link(self::USER, $this->botToken);

        $response = $this->http->get('web/?t=' . $link, ['allow_redirects' => false]);

        $header = $response->getHeaderLine('Set-Cookie');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('./', $response->getHeaderLine('Location'));
        $this->assertSame(1, preg_match('/' . WebSession::COOKIE . '=([^;]+)/', $header, $m), $header);
        // Сверяем подписью, а не строкой: срок сессии сервер считает от своего time().
        $this->assertSame(self::USER, WebSession::verifyCookie($m[1], $this->botToken));
        $this->assertStringContainsString('HttpOnly', $header);
    }

    public function testWebLinkWithBrokenSignatureSetsNothing(): void
    {
        $link = WebSession::link(self::USER, $this->botToken);
        $forged = substr($link, 0, -1) . (str_ends_with($link, 'a') ? 'b' : 'a');

        $response = $this->http->get('web/?t=' . $forged, ['allow_redirects' => false]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    /** Без сессии — заглушка со ссылкой на бота, а не пустой дашборд с 403 в консоли. */
    public function testWebPageWithoutSessionShowsHint(): void
    {
        $response = $this->http->get('web/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/web', (string)$response->getBody());
        $this->assertStringNotContainsString('js/app.js', (string)$response->getBody());
    }

    // --- Доступ (продолжение) --------------------------------------------------

    public function testForeignPersonalLedgerIsForbidden(): void
    {
        $response = $this->get('overview', ['start_param' => '999999']);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Форма ответов ---------------------------------------------------------

    public function testOverviewShape(): void
    {
        $this->seedExpense('кофе', 'еда', 300.0);

        $data = $this->successData($this->get('overview'));

        foreach (['period', 'total', 'count', 'average', 'previous_total', 'by_category', 'limits', 'recent'] as $key) {
            $this->assertArrayHasKey($key, $data, "Mini App читает поле {$key}");
        }

        // Суммы — float, но json_encode печатает целые значения без дробной части,
        // поэтому на клиент приходит 300, а не 300.0.
        $this->assertSame(300, $data['total']);
        $this->assertSame(1, $data['count']);
        $this->assertSame(['еда' => 300], $data['by_category']);
    }

    public function testExpensesShape(): void
    {
        $this->seedExpense('кофе', 'еда', 300.0);

        $data = $this->successData($this->get('expenses'));

        $this->assertCount(1, $data['expenses']);
        $this->assertSame('кофе', $data['expenses'][0]['name']);
    }

    public function testCategoriesShape(): void
    {
        $data = $this->successData($this->get('categories'));

        $this->assertArrayHasKey('all_categories', $data);
        $this->assertArrayHasKey('personal_categories', $data);
        $this->assertContains('еда', $data['all_categories'], 'Системные категории должны приезжать всем');
    }

    public function testLimitsShape(): void
    {
        $data = $this->successData($this->get('limits'));

        $this->assertArrayHasKey('global', $data);
        $this->assertArrayHasKey('categories', $data);
    }

    public function testSuggestionsShape(): void
    {
        $this->seedExpense('кофе', 'еда', 300.0);

        $data = $this->successData($this->get('suggestions'));

        $this->assertSame('кофе', $data['suggestions'][0]['name']);
    }

    public function testAnalyticsShape(): void
    {
        $data = $this->successData($this->get('analytics_by_period', ['periods_count' => '3']));

        $this->assertCount(3, $data);
        $this->assertArrayHasKey('label', $data[0]);
        $this->assertArrayHasKey('categories', $data[0]);
    }

    // --- Ошибки роутера --------------------------------------------------------

    public function testUnknownActionIsBadRequest(): void
    {
        $this->assertSame(400, $this->get('такого_действия_нет')->getStatusCode());
    }

    public function testUnsupportedMethodIsNotAllowed(): void
    {
        $response = $this->request('PATCH', 'expense');

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testInvalidDateIsBadRequest(): void
    {
        $response = $this->get('overview', ['start_date' => 'не дата']);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Жизненный цикл траты --------------------------------------------------

    public function testExpenseCreateUpdateDelete(): void
    {
        $created = $this->request('POST', 'expense', [
            'json' => ['name' => 'кофе', 'category' => 'еда', 'amount' => 300],
        ]);
        $this->assertSame(200, $created->getStatusCode());

        $id = (int)$this->successData($this->get('expenses'))['expenses'][0]['id'];

        $updated = $this->request('PUT', 'expense', [
            'json' => ['id' => $id, 'name' => 'кофе латте', 'amount' => 350],
        ]);
        $this->assertSame(200, $updated->getStatusCode());

        $expense = $this->successData($this->get('expenses'))['expenses'][0];
        $this->assertSame('кофе латте', $expense['name']);
        $this->assertSame('350.00', $expense['amount']);
        $this->assertSame('еда', $expense['category'], 'Незаданные поля берутся из текущей записи');

        $deleted = $this->request('DELETE', 'expense', ['query' => ['id' => $id]]);
        $this->assertSame(200, $deleted->getStatusCode());
        $this->assertSame([], $this->successData($this->get('expenses'))['expenses']);
    }

    public function testCreatingExpenseWithoutAmountIsRejected(): void
    {
        $response = $this->request('POST', 'expense', [
            'json' => ['name' => 'кофе', 'category' => 'еда', 'amount' => 0],
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdatingForeignExpenseIsNotFound(): void
    {
        $response = $this->request('PUT', 'expense', ['json' => ['id' => 999999, 'name' => 'взлом']]);

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- Категории и лимиты ----------------------------------------------------

    public function testCategoryCreateAndDelete(): void
    {
        $created = $this->request('POST', 'category', ['json' => ['name' => 'книги']]);
        $this->assertSame(200, $created->getStatusCode());

        // personal_categories — строки таблицы (id, name, created_at), а не имена:
        // all_categories рядом отдаёт именно имена, и формы намеренно разные.
        $personal = $this->successData($this->get('categories'))['personal_categories'];
        $this->assertContains('книги', array_column($personal, 'name'));
        $this->assertArrayHasKey('created_at', $personal[0]);

        $duplicate = $this->request('POST', 'category', ['json' => ['name' => 'книги']]);
        $this->assertSame(400, $duplicate->getStatusCode(), 'Повтор — не успех');

        $deleted = $this->request('DELETE', 'category', ['query' => ['name' => 'книги']]);
        $this->assertSame(200, $deleted->getStatusCode());
    }

    public function testLimitSetAndDelete(): void
    {
        $set = $this->request('POST', 'limit', ['json' => ['category' => 'еда', 'amount' => 5000]]);
        $this->assertSame(200, $set->getStatusCode());

        $limits = $this->successData($this->get('limits'));
        $this->assertSame('еда', $limits['categories'][0]['category']);

        $deleted = $this->request('DELETE', 'limit', ['query' => ['category' => 'еда']]);
        $this->assertSame(200, $deleted->getStatusCode());
    }

    /** Пустая категория означает общий лимит — на этом держится экран «Лимиты». */
    public function testEmptyCategoryMeansGlobalLimit(): void
    {
        $this->request('POST', 'limit', ['json' => ['category' => '', 'amount' => 50000]]);

        $limits = $this->successData($this->get('limits'));

        $this->assertNotNull($limits['global']);
        // Limit::getAll приводит к float, поэтому в JSON уходит число, а не строка
        // «50000.00», как отдал бы MySQL DECIMAL напрямую.
        $this->assertSame(50000, $limits['global']['limit']);
        $this->assertArrayHasKey('spent', $limits['global']);
    }

    public function testUnknownCategoryLimitIsRejected(): void
    {
        $response = $this->request('POST', 'limit', [
            'json' => ['category' => 'такой категории нет', 'amount' => 100],
        ]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Умный ввод ------------------------------------------------------------

    /**
     * Текст подобран так, чтобы целиком разрешился словарём: поход в Gemini
     * сделал бы тест платным и зависящим от чужой доступности.
     */
    public function testSmartInputResolvedFromDictionary(): void
    {
        (new CategoryHint())->remember(CategoryHint::SHARED, 'кофе', 'еда');

        $response = $this->request('POST', 'expense_smart', ['json' => ['text' => 'кофе 300']]);
        $data = $this->successData($response);

        $this->assertSame([], $data['errors']);
        $this->assertSame('кофе', $data['expenses'][0]['name']);
        $this->assertSame('еда', $data['expenses'][0]['category']);
        $this->assertSame('hint', $data['expenses'][0]['source']);
    }

    public function testSmartInputRequiresText(): void
    {
        $response = $this->request('POST', 'expense_smart', ['json' => ['text' => '  ']]);

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- Инфраструктура теста ---------------------------------------------------

    /**
     * Поднимает встроенный сервер PHP, направленный на тестовую базу.
     *
     * Контейнерный сервер на 8081 читает рабочую базу, и гонять по нему тесты,
     * которые создают и удаляют траты, нельзя.
     */
    private static function startServer(): void
    {
        $log = sys_get_temp_dir() . '/api-contract-server.log';

        self::$server = proc_open(
            ['php', '-S', '127.0.0.1:' . self::PORT, '-t', '/var/www/html'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            '/var/www/html',
            getenv() + ['DB_NAME' => 'telegram_bot_test']
        );

        if (!is_resource(self::$server)) {
            self::markTestSkipped('Не удалось запустить встроенный сервер PHP');
        }

        // Ждём, пока сокет начнёт принимать соединения.
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }

            usleep(100_000);
        }

        self::markTestSkipped('Встроенный сервер PHP не поднялся за 10 секунд, лог: ' . $log);
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $action, array $query = []): ResponseInterface
    {
        return $this->request('GET', $action, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $action, array $options = []): ResponseInterface
    {
        $startParam = $options['query']['start_param'] ?? null;
        unset($options['query']['start_param']);

        $options['query'] = ['action' => $action] + ($options['query'] ?? []);
        $options['headers']['X-Telegram-Init-Data'] = InitDataFactory::create(
            $startParam !== null ? ['start_param' => $startParam] : [],
            $this->botToken
        );

        return $this->http->request($method, 'api.php', $options);
    }

    /** Запрос веб-версии: ни заголовка с подписью, ни Telegram — только cookie. */
    private function withCookie(string $cookie): ResponseInterface
    {
        return $this->http->get('api.php?action=overview', [
            'headers' => ['Cookie' => WebSession::COOKIE . '=' . $cookie],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Данные успешного ответа. Форма конверта `{success, data}` — тоже контракт.
     *
     * @return array<mixed>
     */
    private function successData(ResponseInterface $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $body = $this->body($response);

        $this->assertTrue($body['success']);

        return $body['data'];
    }

    private function seedExpense(string $name, string $category, float $amount): void
    {
        $this->seedUser(self::USER);

        $this->db
            ->prepare('INSERT INTO expenses (user_id, name, category, amount) VALUES (?, ?, ?, ?)')
            ->execute([self::USER, $name, $category, $amount]);
    }
}
