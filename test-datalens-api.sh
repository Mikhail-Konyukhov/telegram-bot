#!/bin/bash

# Тестовый скрипт для проверки DataLens API endpoints

BASE_URL="http://localhost:8081/api.php"

echo "================================"
echo "Тестирование DataLens API"
echo "================================"
echo ""

echo "1. Тест endpoint: expenses (все расходы)"
echo "URL: ${BASE_URL}?endpoint=expenses"
curl -s "${BASE_URL}?endpoint=expenses" | jq '.' || echo "Ошибка или нет данных"
echo ""
echo ""

echo "2. Тест endpoint: expenses-by-category (расходы по категориям)"
echo "URL: ${BASE_URL}?endpoint=expenses-by-category"
curl -s "${BASE_URL}?endpoint=expenses-by-category" | jq '.' || echo "Ошибка или нет данных"
echo ""
echo ""

echo "3. Тест endpoint: expenses-by-period (расходы по периодам - месяц)"
echo "URL: ${BASE_URL}?endpoint=expenses-by-period&period=month"
curl -s "${BASE_URL}?endpoint=expenses-by-period&period=month" | jq '.' || echo "Ошибка или нет данных"
echo ""
echo ""

echo "4. Тест endpoint: limits (лимиты)"
echo "URL: ${BASE_URL}?endpoint=limits"
curl -s "${BASE_URL}?endpoint=limits" | jq '.' || echo "Ошибка или нет данных"
echo ""
echo ""

echo "5. Тест endpoint: expenses-vs-limits (сравнение расходов и лимитов)"
echo "URL: ${BASE_URL}?endpoint=expenses-vs-limits"
curl -s "${BASE_URL}?endpoint=expenses-vs-limits" | jq '.' || echo "Ошибка или нет данных"
echo ""
echo ""

echo "================================"
echo "Тестирование завершено"
echo "================================"


