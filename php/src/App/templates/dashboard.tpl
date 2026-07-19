<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Финансовый дашборд</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <!-- Глобальные данные из PHP для JS -->
    <script>
        window.baseApiUrl = 'api.php?user_id={$params.user_id|escape}&token={$params.token|escape}';
        window.allCategories = {$userCategories|@json_encode};
        window.expenseLabels = [{if $expenses}{foreach from=$expenses key=category item=amount name=exp}'{$category|escape}'{if !$smarty.foreach.exp.last},{/if}{/foreach}{/if}];
        window.expenseValues = [{if $expenses}{foreach from=$expenses key=category item=amount name=exp}{$amount}{if !$smarty.foreach.exp.last},{/if}{/foreach}{/if}];
    </script>
    <script src="js/dashboard.js" defer></script>
</head>

<body>
    <header class="header">
        <div class="container">
            <h1><i class="fas fa-chart-line"></i>Дашборд</h1>
        </div>
    </header>

    <!-- Сводная статистика -->
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-ruble-sign"></i>
                </div>
                <h3>{$totalExpenses|string_format:"%.2f"}</h3>
                <p>Всего расходов</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-tags"></i>
                </div>
                <h3>{count($userCategories)}</h3>
                <p>Категорий</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <h3>{$averageExpense|string_format:"%.2f"}</h3>
                <p>Средняя трата</p>
            </div>
            <div class="stat-card{if $globalLimit !== null && $globalTotal >= $globalLimit} limit-exceeded{elseif $globalLimit !== null && $globalTotal >= $globalLimit * 0.8} limit-warning{/if}">
                <div class="icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <h3>{$globalTotal|string_format:"%.2f"}{if $globalLimit !== null} / {$globalLimit|string_format:"%.2f"}{/if}</h3>
                <p>Общий лимит (30 дней)</p>
                {if $globalLimit !== null}
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: {$progressPercent}%"></div>
                    </div>
                {/if}
            </div>
        </div>
    </div>

    <!-- Фильтры -->
    <div class="container">
        <div class="filters-section">
            <h2 class="filters-title">
                <i class="fas fa-filter"></i>
                Фильтры периода
            </h2>
            <form method="get" id="filterForm" class="filters">
                <input type="hidden" name="user_id" value="{$params.user_id|escape}">
                <input type="hidden" name="token" value="{$params.token|escape}">

                <div class="filter-group">
                    <div class="tooltip">
                        <button type="button" id="btn-today" class="btn btn-secondary">
                            <i class="fas fa-calendar-day"></i> Сегодня
                        </button>
                        <span class="tooltiptext">Показать расходы за сегодня</span>
                    </div>
                    <div class="tooltip">
                        <button type="button" id="btn-week" class="btn btn-secondary">
                            <i class="fas fa-calendar-week"></i> Неделя
                        </button>
                        <span class="tooltiptext">Показать расходы за последние 7 дней</span>
                    </div>
                    <div class="tooltip">
                        <button type="button" id="btn-month" class="btn btn-secondary active">
                            <i class="fas fa-calendar-alt"></i> Месяц
                        </button>
                        <span class="tooltiptext">Показать расходы за последний месяц</span>
                    </div>
                </div>

                <div class="filter-group">
                    <label for="start_date">С:</label>
                    <input type="date" name="start_date" id="start_date" value="{$startDate}" class="form-input">
                </div>

                <div class="filter-group">
                    <label for="end_date">По:</label>
                    <input type="date" name="end_date" id="end_date" value="{$endDate}" class="form-input">
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Показать
                </button>
            </form>
        </div>
    </div>

    <!-- Основной контент -->
    <div class="container">
        <div class="main-content">
        
            <!-- Интерактивный анализ текущего месяца -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-search-dollar"></i>
                        Анализ расходов с {$startDateDisplay} по {$endDateDisplay}
                    </h2>
                </div>
                <div class="section-content">
                    <div class="analysis-grid">
                        <!-- График по категориям -->
                        <div class="analysis-chart">
                            <h3>Расходы по категориям</h3>
                            <div class="chart-container" style="height: 350px;">
                                <canvas id="currentMonthChart"></canvas>
                            </div>
                        </div>

                        <!-- Детальный список трат -->
                        <div class="analysis-details" id="analysisDetails" style="display: none;">
                            <div class="details-header">
                                <div class="details-controls">
                                    <button id="toggleExpandAllBtn" class="btn btn-secondary">Развернуть все</button>
                                    <button id="backToChartBtn" class="btn btn-primary">
                                        <i class="fas fa-arrow-left"></i> К графику
                                    </button>
                                </div>
                            </div>
                            <div class="details-content" id="detailsContent">
                                <!-- Детали будут загружены динамически -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Аналитика по месяцам -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-chart-bar"></i>
                        Аналитика по периодам
                    </h2>
                </div>
                <div class="section-content">
                    <div class="filters" style="margin-bottom: 1.5rem;">
                        <div class="filter-group">
                            <label for="periodTypeMonthly">Период:</label>
                            <select id="periodTypeMonthly" class="form-input form-select">
                                <option value="day">Дни</option>
                                <option value="week">Недели</option>
                                <option value="month" selected>Месяцы</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="periodsCountMonthly">Количество:</label>
                            <input type="number" id="periodsCountMonthly" value="6" min="2" max="24"
                                class="form-input" />
                        </div>
                        <button id="loadMonthlyBtn" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> Построить
                        </button>
                    </div>
                    <button id="toggleAllCategories" class="btn btn-secondary" style="margin-bottom: 1rem;">
                        Отметить все
                    </button>
                    <div class="chart-container" style="height: 400px;">
                        <canvas id="chartByPeriods"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Категории -->
    <div class="container">
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-tags"></i>
                    Управление категориями
                </h2>
            </div>
            <div class="section-content">
                <div class="categories-grid" id="categoriesList">
                    {foreach from=$userCategories item=cat}
                        {assign var=isPersonal value=false}
                        {foreach from=$personalCategories item=p}
                            {if $p.name==$cat}{assign var=isPersonal value=true}{/if}
                        {/foreach}
                        <span class="category-tag {if $isPersonal}personal{else}system{/if}">
                            <i class="fas fa-tag"></i>
                            {$cat}
                            {if $isPersonal}
                                <button class="delete-category" data-name="{$cat}" title="Удалить категорию">
                                    <i class="fas fa-times"></i>
                                </button>
                            {/if}
                        </span>
                    {/foreach}
                </div>
                <div class="add-category-form">
                    <input type="text" id="newCategoryInput" placeholder="Название новой категории" class="form-input">
                    <button id="addCategoryBtn" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Добавить категорию
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Детальная таблица -->
    <div class="container">
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-receipt"></i>
                    Детальный список трат
                </h2>
            </div>
            <div class="section-content">
                <!-- Search and filter controls -->
                <div class="filters" style="margin-bottom: 1.5rem;">
                    <div class="filter-group">
                        <label for="searchExpenses">
                            <i class="fas fa-search"></i> Поиск:
                        </label>
                        <input type="text" id="searchExpenses" placeholder="Поиск по названию..." class="form-input">
                    </div>
                    <div class="filter-group">
                        <label for="filterCategory">
                            <i class="fas fa-filter"></i> Категория:
                        </label>
                        <select id="filterCategory" class="form-input form-select">
                            <option value="">Все категории</option>
                            {foreach from=$userCategories item=cat}
                                <option value="{$cat}">{$cat}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="sortExpenses">
                            <i class="fas fa-sort"></i> Сортировка:
                        </label>
                        <select id="sortExpenses" class="form-input form-select">
                            <option value="date_desc">Дата (новые)</option>
                            <option value="date_asc">Дата (старые)</option>
                            <option value="amount_desc">Сумма (больше)</option>
                            <option value="amount_asc">Сумма (меньше)</option>
                            <option value="name_asc">Название (А-Я)</option>
                            <option value="name_desc">Название (Я-А)</option>
                        </select>
                    </div>
                    <button id="clearFilters" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Очистить
                    </button>
                </div>
                <div class="table-container">
                    <table class="table" id="detailsTable">
        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Категория</th>
                                <th>Название</th>
                                <th>Сумма</th>
                                <th>Действия</th>
                            </tr>
        </thead>
        <tbody>
        {foreach from=$detailedExpenses item=exp}
            <tr data-id="{$exp.id}" data-category="{$exp.category|escape}" data-amount="{$exp.amount}">
                <td>{$exp.ts}</td>
                                    <td>
                                        <span class="category-tag system editable" data-field="category" title="Нажмите для редактирования">
                                            <i class="fas fa-tag"></i>
                                            {$exp.category|escape}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="editable" data-field="name" title="Нажмите для редактирования">
                                            {$exp.name|escape}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="editable" data-field="amount" title="Нажмите для редактирования">
                                            <strong>{$exp.amount|string_format:"%.2f"} ₽</strong>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="hide-expense btn btn-secondary" style="padding: 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-eye-slash"></i> Скрыть
                                        </button>
                                    </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
                </div>
            </div>
        </div>
    </div>


    {* <!-- Экспорт -->
    <div class="container">
        <div style="text-align: center; margin: 2rem 0;">
            <button id="exportCsvBtn" class="btn btn-primary">
                <i class="fas fa-download"></i> Экспорт в CSV
            </button>
        </div>
    </div> *}

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.1.1/dist/chartjs-plugin-zoom.min.js"></script>
</body>

</html>