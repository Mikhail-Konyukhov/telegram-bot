<?php

namespace App\Tests\Unit;

use App\Services\TelegramAuth;
use App\Tests\Support\InitDataFactory;
use PHPUnit\Framework\TestCase;

/**
 * Проверка подписи initData — единственное, что стоит между Mini App и чужими
 * данными: весь API доверяет тому, что вернёт этот класс.
 *
 * Подпись в тестах считает {@see InitDataFactory} собственной реализацией
 * алгоритма — не вызовом проверяемого кода, иначе тест доказывал бы лишь
 * самосогласованность и прошёл бы на сломанной схеме.
 */
class TelegramAuthTest extends TestCase
{
    private const TOKEN = InitDataFactory::BOT_TOKEN;

    public function testAcceptsValidSignature(): void
    {
        $result = TelegramAuth::verify(InitDataFactory::create(), self::TOKEN);

        $this->assertSame(InitDataFactory::USER_ID, $result['user_id']);
        $this->assertNull($result['start_param']);
    }

    public function testReturnsStartParam(): void
    {
        $initData = InitDataFactory::create(['start_param' => '-1001234567890']);

        $result = TelegramAuth::verify($initData, self::TOKEN);

        $this->assertSame('-1001234567890', $result['start_param']);
    }

    /** Пустой start_param равнозначен его отсутствию — иначе LedgerResolver получит ''. */
    public function testEmptyStartParamBecomesNull(): void
    {
        $result = TelegramAuth::verify(InitDataFactory::create(['start_param' => '']), self::TOKEN);

        $this->assertNull($result['start_param']);
    }

    public function testUserIdShortcutReturnsSameId(): void
    {
        $this->assertSame(
            InitDataFactory::USER_ID,
            TelegramAuth::userId(InitDataFactory::create(), self::TOKEN)
        );
    }

    // --- Отказы ---------------------------------------------------------------

    public function testRejectsTamperedPayload(): void
    {
        // Подпись валидна для исходного user, но сам user подменён после подписи.
        $initData = InitDataFactory::create();
        $tampered = str_replace(
            urlencode((string)InitDataFactory::USER_ID),
            urlencode('999999'),
            $initData
        );

        $this->assertNotSame($initData, $tampered, 'Подмена не сработала — тест бы ничего не проверил');
        $this->assertNull(TelegramAuth::verify($tampered, self::TOKEN));
    }

    public function testRejectsForeignToken(): void
    {
        $initData = InitDataFactory::create();

        $this->assertNull(TelegramAuth::verify($initData, '999999:OTHER-TOKEN'));
    }

    public function testRejectsMissingHash(): void
    {
        $initData = http_build_query([
            'auth_date' => (string)time(),
            'user'      => json_encode(['id' => InitDataFactory::USER_ID]),
        ]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    public function testRejectsEmptyHash(): void
    {
        $initData = http_build_query([
            'auth_date' => (string)time(),
            'user'      => json_encode(['id' => InitDataFactory::USER_ID]),
            'hash'      => '',
        ]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    public function testRejectsEmptyInitData(): void
    {
        $this->assertNull(TelegramAuth::verify('', self::TOKEN));
    }

    /**
     * Подпись валидна, но данным больше суток. Кража initData из логов или истории
     * браузера не должна давать бессрочный доступ.
     */
    public function testRejectsExpiredAuthDate(): void
    {
        $initData = InitDataFactory::create(['auth_date' => time() - 86401]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    public function testAcceptsAuthDateJustInsideWindow(): void
    {
        $initData = InitDataFactory::create(['auth_date' => time() - 86399]);

        $this->assertNotNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    public function testRejectsZeroAuthDate(): void
    {
        $this->assertNull(TelegramAuth::verify(InitDataFactory::create(['auth_date' => 0]), self::TOKEN));
    }

    public function testRejectsMissingAuthDate(): void
    {
        $this->assertNull(TelegramAuth::verify(InitDataFactory::create(['auth_date' => null]), self::TOKEN));
    }

    public function testRejectsMissingUser(): void
    {
        $this->assertNull(TelegramAuth::verify(InitDataFactory::create(['user' => null]), self::TOKEN));
    }

    public function testRejectsUserWithoutId(): void
    {
        $initData = InitDataFactory::create(['user' => json_encode(['first_name' => 'Тест'])]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    /**
     * id строкой отклоняется из-за `is_int`. Telegram присылает число, а строка
     * означает, что JSON собрал кто-то другой.
     */
    public function testRejectsStringUserId(): void
    {
        $initData = InitDataFactory::create(['user' => json_encode(['id' => '424242'])]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    public function testRejectsNonPositiveUserId(): void
    {
        $initData = InitDataFactory::create(['user' => json_encode(['id' => 0])]);

        $this->assertNull(TelegramAuth::verify($initData, self::TOKEN));
    }

    // --- Поле signature -------------------------------------------------------
    //
    // Оно появилось позже hash и в data-check-string по документации не входит,
    // но клиенты трактуют это по-разному, поэтому verify() пробует оба варианта.
    // Обе строки подписаны одним секретом, так что безопасность не страдает —
    // но оба пути должны работать, иначе часть клиентов молча получит 403.

    public function testAcceptsHashComputedWithoutSignatureField(): void
    {
        $params = [
            'auth_date' => (string)time(),
            'user'      => json_encode(['id' => InitDataFactory::USER_ID]),
            'signature' => 'some-ed25519-signature',
        ];

        // Подписываем всё, кроме signature — первый кандидат в verify().
        $withoutSignature = $params;
        unset($withoutSignature['signature']);
        $params['hash'] = InitDataFactory::hash($withoutSignature, self::TOKEN);

        $result = TelegramAuth::verify(http_build_query($params), self::TOKEN);

        $this->assertSame(InitDataFactory::USER_ID, $result['user_id']);
    }

    public function testAcceptsHashComputedIncludingSignatureField(): void
    {
        // Подписываем вместе с signature — второй кандидат в verify().
        $initData = InitDataFactory::create(['signature' => 'some-ed25519-signature']);

        $result = TelegramAuth::verify($initData, self::TOKEN);

        $this->assertSame(InitDataFactory::USER_ID, $result['user_id']);
    }
}
