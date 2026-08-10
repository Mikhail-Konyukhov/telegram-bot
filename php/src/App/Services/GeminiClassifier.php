<?php

namespace App\Services;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;

/**
 * Классификация трат через Gemini Flash.
 *
 * Вызывается только на том, чего не нашлось в словаре категорий
 * (см. {@see \App\Models\CategoryHint}), и всё сообщение уезжает одним
 * запросом, а не по запросу на позицию.
 */
class GeminiClassifier
{
    /**
     * Flash-Lite — самая дешёвая и быстрая модель линейки, для разметки коротких
     * строк по готовому списку категорий её хватает с запасом.
     *
     * Взят плавающий алиас, а не конкретная версия: Google снимает старые модели
     * с обслуживания для новых ключей, и закреплённая версия однажды начинает
     * отвечать 404 («no longer available to new users») — на 2.5-flash-lite это
     * уже случилось. Ответ ограничен responseSchema с enum по категориям
     * пользователя, поэтому смена модели под алиасом формат не ломает.
     *
     * Список ID: ai.google.dev/gemini-api/docs/models
     */
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent';

    private HttpClient $http;
    private string $apiKey;

    /** Минимальный интервал между запросами, микросекунды; 0 — без ограничения */
    private int $minIntervalUs;

    private float $lastRequestAt = 0.0;

    public function __construct(HttpClient $http, string $apiKey)
    {
        $this->http = $http;
        $this->apiKey = $apiKey;
        // Из окружения, как параметры БД в App\Database: это настройка среды,
        // а не приложения, и в вебхуке она не нужна вовсе — там объект живёт
        // один запрос.
        $this->minIntervalUs = max(0, (int)(getenv('GEMINI_MIN_INTERVAL_MS') ?: 0)) * 1000;
    }

    /**
     * Раскладывает названия трат по категориям.
     *
     * @param string[] $names Названия трат
     * @param string[] $categories Допустимые категории пользователя
     * @return array<string, string> название => категория. Пустой массив,
     *                               если API недоступен или ответил мусором.
     */
    public function classify(array $names, array $categories): array
    {
        $names = array_values(array_unique($names));
        $categories = array_values($categories);

        if (!$names || !$categories) {
            return [];
        }

        $this->throttle();

        try {
            $response = $this->http->post(self::ENDPOINT, [
                'headers' => ['x-goog-api-key' => $this->apiKey],
                'json' => $this->payload($names, $categories),
            ]);
        } catch (ConnectException $e) {
            // Сеть не поднялась или вышел таймаут — само по себе временное.
            throw new GeminiUnavailable('Gemini недоступен: ' . $e->getMessage(), 0, $e);
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();

            // 429 — упёрлись в лимит ключа, 5xx — сбой на стороне Google: и то,
            // и другое проходит со второй попытки. Остальное (401/403 — ключ,
            // 400 — кривой запрос) повторять бессмысленно.
            if ($status === 429 || $status >= 500) {
                throw new GeminiUnavailable("Gemini ответил {$status}", 0, $e);
            }

            error_log('Gemini request failed: ' . $e->getMessage());
            return [];
        } catch (\Throwable $e) {
            error_log('Gemini request failed: ' . $e->getMessage());
            return [];
        }

        $labels = $this->extractLabels((string)$response->getBody());

        // Модель обязана вернуть по метке на позицию и ровно в том же порядке.
        // Если не сошлось — считать соответствие «название → категория» нельзя,
        // и лучше отдать пустоту, чем разложить траты наугад.
        if (count($labels) !== count($names)) {
            error_log('Gemini returned ' . count($labels) . ' labels for ' . count($names) . ' items');
            return [];
        }

        $result = [];
        foreach ($names as $i => $name) {
            // responseSchema ограничивает ответ enum'ом, но проверка дешевле,
            // чем чужая категория, записанная в общий словарь.
            if (in_array($labels[$i], $categories, true)) {
                $result[$name] = $labels[$i];
            }
        }

        return $result;
    }

    /**
     * Выдерживает паузу между запросами.
     *
     * Ключ Gemini один на всех пользователей, и лимит у него на ключ, а не на
     * пользователя — значит ограничивать темп надо глобально. Приём верен ровно
     * пока потребитель очереди один: со вторым воркером поля в объекте перестанет
     * хватать и понадобится общий счётчик.
     */
    private function throttle(): void
    {
        if ($this->minIntervalUs <= 0 || $this->lastRequestAt === 0.0) {
            $this->lastRequestAt = microtime(true);
            return;
        }

        $elapsedUs = (microtime(true) - $this->lastRequestAt) * 1_000_000;

        if ($elapsedUs < $this->minIntervalUs) {
            usleep((int)($this->minIntervalUs - $elapsedUs));
        }

        $this->lastRequestAt = microtime(true);
    }

    /**
     * @param string[] $names
     * @param string[] $categories
     */
    private function payload(array $names, array $categories): array
    {
        $list = '';
        foreach ($names as $i => $name) {
            $list .= ($i + 1) . '. ' . $name . "\n";
        }

        return [
            'systemInstruction' => [
                'parts' => [[
                    'text' => 'Ты классифицируешь личные траты. На вход — нумерованный список '
                        . 'коротких названий покупок. Верни JSON-массив категорий: по одной '
                        . 'на каждый пункт, в том же порядке, ровно из предложенного списка. '
                        . 'Если пункт непонятен, выбери наиболее правдоподобную категорию.',
                ]],
            ],
            'contents' => [[
                'parts' => [[
                    'text' => "Категории:\n" . implode("\n", $categories) . "\n\nТраты:\n" . $list,
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'ARRAY',
                    'minItems' => count($names),
                    'maxItems' => count($names),
                    'items' => [
                        'type' => 'STRING',
                        'enum' => $categories,
                    ],
                ],
            ],
        ];
    }

    /**
     * Достаёт массив категорий из ответа Gemini.
     *
     * @return string[]
     */
    private function extractLabels(string $body): array
    {
        $data = json_decode($body, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text)) {
            error_log('Gemini response without text part: ' . mb_substr($body, 0, 500));
            return [];
        }

        $labels = json_decode($text, true);

        return is_array($labels) ? array_map('strval', $labels) : [];
    }
}
