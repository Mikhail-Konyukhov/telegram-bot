<?php
/* Smarty version 5.5.1, created on 2026-03-24 08:40:13
  from 'file:dashboard.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.5.1',
  'unifunc' => 'content_69c24ded4352c0_65948344',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'e049a1450a9ef05a14bc412c0ed12a3b624dd959' => 
    array (
      0 => 'dashboard.tpl',
      1 => 1773133785,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_69c24ded4352c0_65948344 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/var/www/html/src/App/templates';
?><!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Финансовый дашборд</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <!-- Глобальные данные из PHP для JS -->
    <?php echo '<script'; ?>
>
        window.baseApiUrl = 'api.php?user_id=<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('params')['user_id'], ENT_QUOTES, 'UTF-8', true);?>
&token=<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('params')['token'], ENT_QUOTES, 'UTF-8', true);?>
';
        window.allCategories = <?php echo json_encode($_smarty_tpl->getValue('userCategories'));?>
;
        window.expenseLabels = [<?php if ($_smarty_tpl->getValue('expenses')) {
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('expenses'), 'amount', false, 'category', 'exp', array (
  'last' => true,
  'iteration' => true,
  'total' => true,
));
$foreach0DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('category')->value => $_smarty_tpl->getVariable('amount')->value) {
$foreach0DoElse = false;
$_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['iteration']++;
$_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['last'] = $_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['iteration'] === $_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['total'];
?>'<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('category'), ENT_QUOTES, 'UTF-8', true);?>
'<?php if (!($_smarty_tpl->getValue('__smarty_foreach_exp')['last'] ?? null)) {?>,<?php }
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);
}?>];
        window.expenseValues = [<?php if ($_smarty_tpl->getValue('expenses')) {
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('expenses'), 'amount', false, 'category', 'exp', array (
  'last' => true,
  'iteration' => true,
  'total' => true,
));
$foreach1DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('category')->value => $_smarty_tpl->getVariable('amount')->value) {
$foreach1DoElse = false;
$_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['iteration']++;
$_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['last'] = $_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['iteration'] === $_smarty_tpl->tpl_vars['__smarty_foreach_exp']->value['total'];
echo $_smarty_tpl->getValue('amount');
if (!($_smarty_tpl->getValue('__smarty_foreach_exp')['last'] ?? null)) {?>,<?php }
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);
}?>];
    <?php echo '</script'; ?>
>
    <?php echo '<script'; ?>
 src="js/dashboard.js" defer><?php echo '</script'; ?>
>
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
                <h3><?php echo sprintf("%.2f",$_smarty_tpl->getValue('totalExpenses'));?>
</h3>
                <p>Всего расходов</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-tags"></i>
                </div>
                <h3><?php echo $_smarty_tpl->getSmarty()->getModifierCallback('count')($_smarty_tpl->getValue('userCategories'));?>
</h3>
                <p>Категорий</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <h3><?php echo sprintf("%.2f",$_smarty_tpl->getValue('averageExpense'));?>
</h3>
                <p>Средняя трата</p>
            </div>
            <div class="stat-card<?php if ($_smarty_tpl->getValue('globalLimit') !== null && $_smarty_tpl->getValue('globalTotal') >= $_smarty_tpl->getValue('globalLimit')) {?> limit-exceeded<?php } elseif ($_smarty_tpl->getValue('globalLimit') !== null && $_smarty_tpl->getValue('globalTotal') >= $_smarty_tpl->getValue('globalLimit')*0.8) {?> limit-warning<?php }?>">
                <div class="icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <h3><?php echo sprintf("%.2f",$_smarty_tpl->getValue('globalTotal'));
if ($_smarty_tpl->getValue('globalLimit') !== null) {?> / <?php echo sprintf("%.2f",$_smarty_tpl->getValue('globalLimit'));
}?></h3>
                <p>Общий лимит (30 дней)</p>
                <?php if ($_smarty_tpl->getValue('globalLimit') !== null) {?>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $_smarty_tpl->getValue('progressPercent');?>
%"></div>
                    </div>
                <?php }?>
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
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('params')['user_id'], ENT_QUOTES, 'UTF-8', true);?>
">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('params')['token'], ENT_QUOTES, 'UTF-8', true);?>
">

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
                    <input type="date" name="start_date" id="start_date" value="<?php echo $_smarty_tpl->getValue('startDate');?>
" class="form-input">
                </div>

                <div class="filter-group">
                    <label for="end_date">По:</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo $_smarty_tpl->getValue('endDate');?>
" class="form-input">
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
                        Анализ расходов с <?php echo $_smarty_tpl->getValue('startDateDisplay');?>
 по <?php echo $_smarty_tpl->getValue('endDateDisplay');?>

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
                    <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('userCategories'), 'cat');
$foreach2DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('cat')->value) {
$foreach2DoElse = false;
?>
                        <?php $_smarty_tpl->assign('isPersonal', false, false, NULL);?>
                        <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('personalCategories'), 'p');
$foreach3DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('p')->value) {
$foreach3DoElse = false;
?>
                            <?php if ($_smarty_tpl->getValue('p')['name'] == $_smarty_tpl->getValue('cat')) {
$_smarty_tpl->assign('isPersonal', true, false, NULL);
}?>
                        <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
                        <span class="category-tag <?php if ($_smarty_tpl->getValue('isPersonal')) {?>personal<?php } else { ?>system<?php }?>">
                            <i class="fas fa-tag"></i>
                            <?php echo $_smarty_tpl->getValue('cat');?>

                            <?php if ($_smarty_tpl->getValue('isPersonal')) {?>
                                <button class="delete-category" data-name="<?php echo $_smarty_tpl->getValue('cat');?>
" title="Удалить категорию">
                                    <i class="fas fa-times"></i>
                                </button>
                            <?php }?>
                        </span>
                    <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
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
                            <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('userCategories'), 'cat');
$foreach4DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('cat')->value) {
$foreach4DoElse = false;
?>
                                <option value="<?php echo $_smarty_tpl->getValue('cat');?>
"><?php echo $_smarty_tpl->getValue('cat');?>
</option>
                            <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
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
        <?php
$_from = $_smarty_tpl->getSmarty()->getRuntime('Foreach')->init($_smarty_tpl, $_smarty_tpl->getValue('detailedExpenses'), 'exp');
$foreach5DoElse = true;
foreach ($_from ?? [] as $_smarty_tpl->getVariable('exp')->value) {
$foreach5DoElse = false;
?>
            <tr data-id="<?php echo $_smarty_tpl->getValue('exp')['id'];?>
" data-category="<?php echo htmlspecialchars((string)$_smarty_tpl->getValue('exp')['category'], ENT_QUOTES, 'UTF-8', true);?>
" data-amount="<?php echo $_smarty_tpl->getValue('exp')['amount'];?>
">
                <td><?php echo $_smarty_tpl->getValue('exp')['ts'];?>
</td>
                                    <td>
                                        <span class="category-tag system editable" data-field="category" title="Нажмите для редактирования">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars((string)$_smarty_tpl->getValue('exp')['category'], ENT_QUOTES, 'UTF-8', true);?>

                                        </span>
                                    </td>
                                    <td>
                                        <span class="editable" data-field="name" title="Нажмите для редактирования">
                                            <?php echo htmlspecialchars((string)$_smarty_tpl->getValue('exp')['name'], ENT_QUOTES, 'UTF-8', true);?>

                                        </span>
                                    </td>
                                    <td>
                                        <span class="editable" data-field="amount" title="Нажмите для редактирования">
                                            <strong><?php echo sprintf("%.2f",$_smarty_tpl->getValue('exp')['amount']);?>
 ₽</strong>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="hide-expense btn btn-secondary" style="padding: 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-eye-slash"></i> Скрыть
                                        </button>
                                    </td>
            </tr>
        <?php
}
$_smarty_tpl->getSmarty()->getRuntime('Foreach')->restore($_smarty_tpl, 1);?>
        </tbody>
    </table>
                </div>
            </div>
        </div>
    </div>


    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <?php echo '<script'; ?>
 src="https://cdn.jsdelivr.net/npm/chart.js"><?php echo '</script'; ?>
>
    <?php echo '<script'; ?>
 src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.1.1/dist/chartjs-plugin-zoom.min.js"><?php echo '</script'; ?>
>
</body>

</html><?php }
}
