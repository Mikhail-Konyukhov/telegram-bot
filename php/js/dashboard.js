   // Toast notification system
    function showToast(message, type, duration) {
        type = type || 'info';
        duration = duration || 4000;
        
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            info: 'fas fa-info-circle',
            warning: 'fas fa-exclamation-triangle'
        };
        
        toast.innerHTML = '<i class="' + icons[type] + '"></i>' +
                         '<span>' + message + '</span>' +
                         '<button class="toast-close" onclick="this.parentElement.remove()">×</button>';
        
        container.appendChild(toast);
        
        // Trigger animation
        setTimeout(function() { toast.classList.add('show'); }, 100);
        
        // Auto remove
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    }
    // Данные для графиков
    // Global variables injected from template
    // Глобальные переменные, доступные во всех функциях
    const baseApiUrl = window.baseApiUrl;
    const expenseLabels = window.expenseLabels || [];
    const expenseValues = window.expenseValues || [];
    const allCategories = window.allCategories || [];

    // Функция создания графика текущего месяца
    function createCurrentMonthChart() {
        const currentMonthCtx = document.getElementById('currentMonthChart').getContext('2d');
        
        // Проверяем наличие данных
        if (!expenseLabels || expenseLabels.length === 0 || !expenseValues || expenseValues.length === 0) {
            console.warn('Нет данных для currentMonthChart');
            // Создаем пустой график с сообщением
            window.currentMonthChart = new Chart(currentMonthCtx, {
                type: 'bar',
                data: {
                    labels: ['Нет данных'],
                    datasets: [{
                        label: 'Нет данных',
                        data: [0],
                        backgroundColor: ['#6b7280'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
            return;
        }
        
        // Создаем один dataset с данными
        const currentMonthData = {
            labels: expenseLabels,
            datasets: [{
                label: 'Текущий месяц',
                data: expenseValues,
                backgroundColor: [
                    '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                    '#06b6d4', '#84cc16', '#f97316', '#ec4899', '#6b7280'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        };
        
        window.currentMonthChart = new Chart(currentMonthCtx, {
            type: 'bar',
            data: currentMonthData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'left',
                        align: 'start',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 16,
                            padding: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toFixed(2) + ' ₽';
                            }
                        }
                    },
                    zoom: {
                        pan: {
                            enabled: true,
                            mode: 'xy'
                        },
                        zoom: {
                            wheel: { enabled: true },
                            pinch: { enabled: true },
                            mode: 'xy'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toFixed(0) + ' ₽';
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const elementIndex = elements[0].index;
                        const categoryName = this.data.labels[elementIndex];
                        showCategoryDetails(categoryName);
                    }
                }
            }
        });
    }
    
    // Функция создания графика категорий текущего месяца
    function createMonthlyCategoriesChart() {
        const ctx = document.getElementById('currentMonthChart').getContext('2d');
        if (window.monthlyCategoriesChart) {
            window.monthlyCategoriesChart.destroy();
        }

        const labels = expenseLabels;
        const data = expenseValues;
        const backgroundColors = [
            '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
            '#06b6d4', '#84cc16', '#f97316', '#ec4899', '#6b7280'
        ];

        window.monthlyCategoriesChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Сумма',
                    data: data,
                    backgroundColor: backgroundColors,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toFixed(2) + ' ₽';
                            }
                        }
                    },
                    zoom: {
                        pan: {
                            enabled: true,
                            mode: 'xy'
                        },
                        zoom: {
                            wheel: { enabled: true },
                            pinch: { enabled: true },
                            mode: 'xy'
                        }
                    }
                },
                scales: {
                    x: {
                        // Категории по оси X
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toFixed(0) + ' ₽';
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const elementIndex = elements[0].index;
                        const categoryName = this.data.labels[elementIndex];
                        showCategoryDetails(categoryName);
                    }
                }
            }
        });
    }
    
    // Инициализация при загрузке страницы
    document.addEventListener('DOMContentLoaded', function() {
        // Создаем график категорий текущего месяца
        createMonthlyCategoriesChart();
        
        // Инициализация графика аналитики по месяцам
        const loadMonthlyBtn = document.getElementById('loadMonthlyBtn');
        if (loadMonthlyBtn) {
            loadMonthlyBtn.addEventListener('click', createAnalyticsByPeriodChart);
        }
        createAnalyticsByPeriodChart();
    });

    // Функция создания графика месячной аналитики
    function createAnalyticsByPeriodChart() {
        const periodType = document.getElementById('periodTypeMonthly').value;
        const periodsCount = document.getElementById('periodsCountMonthly').value;
        // Получаем данные за выбранный период
        const url = baseApiUrl + '&action=analytics_by_period&period_type=' + periodType + '&periods_count=' + periodsCount;
        fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            const monthlyCtx = document.getElementById('chartByPeriods').getContext('2d');
            const monthlyData = data.data;
            
            // Подготавливаем данные для стэкованного графика
            const labels = monthlyData.map(item => item.label);
            const categories = [...new Set(monthlyData.flatMap(item => Object.keys(item.categories)))];
            
            const datasets = categories.map((category, index) => ({
                label: category,
                data: monthlyData.map(item => item.categories[category] || 0),
                backgroundColor: [
                    '#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                    '#06b6d4', '#84cc16', '#f97316', '#ec4899', '#6b7280'
                ][index % 10],
                borderWidth: 1,
                borderColor: '#ffffff'
            }));
            
            // Удаляем старый график, если есть
            if (window.chartByPeriods && typeof window.chartByPeriods.destroy === 'function') {
                window.chartByPeriods.destroy();
                window.chartByPeriods = null;
            }
            // Создаём новый график и сохраняем в window.chartByPeriods
            window.chartByPeriods = new Chart(monthlyCtx, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'left',
                            align: 'start',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 16,
                                padding: 16
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' ₽';
                                },
                                footer: function(tooltipItems) {
                                    let total = 0;
                                    tooltipItems.forEach(item => total += item.parsed.y);
                                    return 'Итого: ' + total.toFixed(2) + ' ₽';
                                }
                            }
                        },
                        zoom: {
                            pan: {
                                enabled: true,
                                mode: 'xy'
                            },
                            zoom: {
                                wheel: { enabled: true },
                                pinch: { enabled: true },
                                mode: 'xy'
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(0) + ' ₽';
                                }
                            }
                        }
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    }
                }
            });
        })
        .catch(error => {
            console.error('Ошибка загрузки аналитики:', error);
            // Если нужна заглушка, раскомментируйте следующую строку:
            // createDummyMonthlyChart();
        });
    }

    if (typeof createDummyMonthlyChart !== 'function') {
        function createDummyMonthlyChart() {
            // Простейшая заглушка: очищает график
            const monthlyCtx = document.getElementById('currentMonthChart').getContext('2d');
            monthlyCtx.clearRect(0, 0, monthlyCtx.canvas.width, monthlyCtx.canvas.height);
        }
    }

    // Функция показа деталей категории
    function showCategoryDetails(categoryName) {
        const analysisGrid = document.querySelector('.analysis-grid');
        const analysisDetails = document.getElementById('analysisDetails');
        // const selectedCategoryTitle = document.getElementById('selectedCategoryTitle');
        const detailsContent = document.getElementById('detailsContent');
        
        // Анимация перехода
        analysisGrid.classList.add('details-mode');
        analysisDetails.style.display = 'block';
        // if (selectedCategoryTitle) {
        //     selectedCategoryTitle.textContent = 'Детали категории: ' + categoryName;
        // }
        
        // Загружаем детали категории
        loadCategoryDetails(categoryName, detailsContent);
        // showToast('Загружаем детали категории...', 'info', 2000); // тосты отключены
    }

    // Функция загрузки деталей категории
    function loadCategoryDetails(categoryName, container) {
        // Получаем текущие данные из детальной таблицы
        const expenses = Array.from(document.querySelectorAll('#detailsTable tbody tr')).map(row => ({
            id: row.dataset.id,
            name: row.cells[2].textContent.trim(),
            category: row.cells[1].textContent.replace(/.*\s/, '').trim(),
            amount: parseFloat(row.dataset.amount),
            date: row.cells[0].textContent.trim()
        }));
        
        // Группируем по категориям
        const groupedExpenses = {};
        expenses.forEach(expense => {
            if (!groupedExpenses[expense.category]) {
                groupedExpenses[expense.category] = [];
            }
            groupedExpenses[expense.category].push(expense);
        });
        
        // Создаем HTML для категорий
        let html = '';
        Object.keys(groupedExpenses).forEach(category => {
            const categoryExpenses = groupedExpenses[category];
            const totalAmount = categoryExpenses.reduce((sum, exp) => sum + exp.amount, 0);
            const isExpanded = category === categoryName;
            
            html += '<div class="category-group">';
            html += '<div class="category-header ' + (isExpanded ? 'expanded' : '') + '" data-category="' + category + '">';
            html += '<div class="category-title">';
            html += '<i class="fas fa-tag"></i>';
            html += '<span>' + category + ' (' + categoryExpenses.length + ')</span>';
            html += '</div>';
            html += '<div class="category-amount">' + totalAmount.toFixed(2) + ' ₽</div>';
            html += '<div class="category-toggle"><i class="fas fa-chevron-down"></i></div>';
            html += '</div>';
            html += '<div class="category-expenses ' + (isExpanded ? 'expanded' : '') + '">';
            
            categoryExpenses.forEach(expense => {
                html += '<div class="expense-item" data-id="' + expense.id + '">';
                html += '<div class="expense-info">';
                html += '<div class="expense-name">' + expense.name + '</div>';
                html += '<div class="expense-date">' + expense.date + '</div>';
                html += '</div>';
                html += '<div class="expense-amount">' + expense.amount.toFixed(2) + ' ₽</div>';
                html += '<select class="expense-category-select" data-expense-id="' + expense.id + '">';
                
                // Добавляем все категории в селект
                allCategories.forEach(function(cat){
                    html += '<option value="' + cat + '" ' + (expense.category === cat ? 'selected' : '') + '>' + cat + '</option>';
                });
                
                html += '</select>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '</div>';
        });
        
        container.innerHTML = html;
        
        // Добавляем обработчики событий
        initializeCategoryHandlers();
    }

    // Инициализация обработчиков для категорий
    function initializeCategoryHandlers() {
        // Обработчики для сворачивания/разворачивания категорий
        document.querySelectorAll('.category-header').forEach(header => {
            header.addEventListener('click', function() {
                const category = this.dataset.category;
                const expenses = this.nextElementSibling;
                
                this.classList.toggle('expanded');
                expenses.classList.toggle('expanded');
                // Скролл к развернутой категории
                if (this.classList.contains('expanded')) {
                    this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
        
        // Обработчики для изменения категории
        document.querySelectorAll('.expense-category-select').forEach(select => {
            select.addEventListener('change', function() {
                const expenseId = this.dataset.expenseId;
                const newCategory = this.value;
                
                updateExpenseCategory(expenseId, newCategory);
            });
        });
    }

    // Функция обновления категории траты
    function updateExpenseCategory(expenseId, newCategory) {
        // Найти строку по id
        const row = document.querySelector(`tr[data-id='${expenseId}']`);
        if (!row) return;

        const name = row.querySelector('[data-field="name"]').textContent.trim();
        const amount = parseFloat(row.dataset.amount);
        const date = row.cells[0].textContent.trim();

        fetch(baseApiUrl + '&action=expense', {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: expenseId,
                name: name,
                category: newCategory,
                amount: amount,
                date: date
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Категория обновлена', 'success');
                // Перезагружаем детали
                setTimeout(() => {
                    const selectedCategory = document.getElementById('selectedCategoryTitle').textContent.replace('Детали категории: ', '');
                    loadCategoryDetails(selectedCategory, document.getElementById('detailsContent'));
                }, 500);
            } else {
                showToast(data.message || 'Ошибка при обновлении', 'error');
            }
        })
        .catch(error => {
            showToast('Ошибка сети', 'error');
        });
    }

    // Обработчики кнопок управления деталями
    document.getElementById('backToChartBtn').addEventListener('click', function() {
        const analysisGrid = document.querySelector('.analysis-grid');
        const analysisDetails = document.getElementById('analysisDetails');
        
        analysisGrid.classList.remove('details-mode');
        setTimeout(() => {
            analysisDetails.style.display = 'none';
        }, 300);
    });

    document.getElementById('toggleExpandAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.category-header').forEach(header => {
            header.classList.add('expanded');
            header.nextElementSibling.classList.add('expanded');
        });
    });

    document.getElementById('collapseAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.category-header').forEach(header => {
            header.classList.remove('expanded');
            header.nextElementSibling.classList.remove('expanded');
        });
    });

    // Period buttons
    function formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    function setActiveButton(activeId) {
        document.querySelectorAll('.filter-group .btn-secondary').forEach(btn => btn.classList.remove('active'));
        document.getElementById(activeId).classList.add('active');
    }

    function setPeriod(type) {
        const today = new Date();
        let start, end;

        switch (type) {
            case 'today':
                start = end = today;
                setActiveButton('btn-today');
                break;
            case 'week':
                end = today;
                start = new Date();
                start.setDate(end.getDate() - 6);
                setActiveButton('btn-week');
                break;
            case 'month':
                end = today;
                start = new Date();
                start.setMonth(end.getMonth() - 1);
                setActiveButton('btn-month');
                break;
        }

        document.getElementById('start_date').value = formatDate(start);
        document.getElementById('end_date').value = formatDate(end);
        document.getElementById('filterForm').submit();
    }

    document.getElementById('btn-today').addEventListener('click', () => setPeriod('today'));
    document.getElementById('btn-week').addEventListener('click', () => setPeriod('week'));
    document.getElementById('btn-month').addEventListener('click', () => setPeriod('month'));

    /* === Категории === */
    // const allCategories = window.allCategories || []; // This line is moved to the top

    document.getElementById('addCategoryBtn').addEventListener('click', () => {
        const input = document.getElementById('newCategoryInput');
        const btn = document.getElementById('addCategoryBtn');
        const name = input.value.trim();
        
        if (!name) {
            showToast('Введите название категории', 'error');
            return;
        }
        
        // Loading state
        btn.classList.add('loading');
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Добавление...';
        
        fetch(baseApiUrl + '&action=category', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({name: name})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Категория добавлена', 'success');
                input.value = '';
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message || 'Ошибка при добавлении категории', 'error');
            }
        })
        .catch(error => {
            showToast('Ошибка сети', 'error');
        })
        .finally(() => {
            btn.classList.remove('loading');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    document.querySelectorAll('.delete-category').forEach(btn=>{
        btn.addEventListener('click', () => {
            const name = btn.dataset.name;
            if(!confirm('Удалить категорию '+name+'?')) return;
            
            // Loading state
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            
            fetch(baseApiUrl + '&action=category&name=' + encodeURIComponent(name), {method:'DELETE'})
                .then(r=>r.json())
                .then(data => {
                    if (data.success) {
                        showToast('Категория удалена', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Ошибка при удалении категории', 'error');
                        btn.innerHTML = '<i class="fas fa-times"></i>';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    showToast('Ошибка сети', 'error');
                    btn.innerHTML = '<i class="fas fa-times"></i>';
                    btn.disabled = false;
                });
        });
    });

    /* === Скрытие трат === */
    const chart = window.currentMonthChart; // Chart.js instance

    function updateAggregates(category, amountChange) {
        // Update aggregated table cell and chart data
        const row = document.querySelector(`tbody tr td:first-child:contains('${category}')`);
    }

    document.querySelectorAll('.hide-expense').forEach(btn=>{
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            const amount = parseFloat(tr.dataset.amount);
            const category = tr.dataset.category;
            
            // Animate row removal
            tr.style.opacity = '0.5';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            btn.disabled = true;
            
            // Update chart data
            const idx = chart.data.labels.indexOf(category);
            if (idx > -1) {
                chart.data.datasets[0].data[idx] -= amount;
                if (chart.data.datasets[0].data[idx] < 0) chart.data.datasets[0].data[idx] = 0;
                chart.update();
            }
            
            // Update summary table
            const summaryRows = document.querySelectorAll('.table tbody tr');
            summaryRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 2 && cells[0].textContent.trim() === category) {
                    const currentAmount = parseFloat(cells[1].textContent.replace(/[^\d.-]/g, ''));
                    const newAmount = Math.max(0, currentAmount - amount);
                    cells[1].innerHTML = '<strong>' + newAmount.toFixed(2) + ' ₽</strong>';
                }
            });
            
            // Update total in stats
            const totalCard = document.querySelector('.stat-card h3');
            if (totalCard) {
                const currentTotal = parseFloat(totalCard.textContent.replace(/[^\d.-]/g, ''));
                const newTotal = Math.max(0, currentTotal - amount);
                totalCard.textContent = newTotal.toFixed(2);
            }
            
            setTimeout(() => {
                tr.style.transition = 'all 0.3s ease';
                tr.style.transform = 'translateX(-100%)';
                setTimeout(() => tr.remove(), 300);
            }, 500);
            
            showToast('Трата скрыта', 'info');
        });
    });



    /* === Search and Filter === */
    let originalTableRows = [];
    
    // Store original rows on page load
    document.addEventListener('DOMContentLoaded', () => {
        const tbody = document.querySelector('#detailsTable tbody');
        originalTableRows = Array.from(tbody.querySelectorAll('tr')).map(row => ({
            element: row.cloneNode(true),
            data: {
                date: row.cells[0].textContent.trim(),
                category: row.cells[1].textContent.trim(),
                name: row.cells[2].textContent.trim(),
                amount: parseFloat(row.dataset.amount || '0')
            }
        }));
    });
    
    function filterAndSortTable() {
        const searchTerm = document.getElementById('searchExpenses').value.toLowerCase();
        const categoryFilter = document.getElementById('filterCategory').value;
        const sortBy = document.getElementById('sortExpenses').value;
        
        let filteredRows = originalTableRows.filter(row => {
            const matchesSearch = row.data.name.toLowerCase().includes(searchTerm);
            const matchesCategory = !categoryFilter || row.data.category.includes(categoryFilter);
            return matchesSearch && matchesCategory;
        });
        
        // Sort rows
        filteredRows.sort((a, b) => {
            switch (sortBy) {
                case 'date_desc':
                    return new Date(b.data.date) - new Date(a.data.date);
                case 'date_asc':
                    return new Date(a.data.date) - new Date(b.data.date);
                case 'amount_desc':
                    return b.data.amount - a.data.amount;
                case 'amount_asc':
                    return a.data.amount - b.data.amount;
                case 'name_asc':
                    return a.data.name.localeCompare(b.data.name);
                case 'name_desc':
                    return b.data.name.localeCompare(a.data.name);
                default:
                    return 0;
            }
        });
        
        // Update table
        const tbody = document.querySelector('#detailsTable tbody');
        tbody.innerHTML = '';
        filteredRows.forEach(row => {
            const newRow = row.element.cloneNode(true);
            // Re-attach event listeners
            const hideBtn = newRow.querySelector('.hide-expense');
            if (hideBtn) {
                hideBtn.addEventListener('click', () => {
                    const tr = hideBtn.closest('tr');
                    const amount = parseFloat(tr.dataset.amount);
                    const category = tr.dataset.category;
                    
                                         // Same logic as before for hiding expenses
                     tr.style.opacity = '0.5';
                     hideBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                     hideBtn.disabled = true;
                     
                     const chartRef = window.currentMonthChart;
                     if (chartRef) {
                         const idx = chartRef.data.labels.indexOf(category);
                         if (idx > -1) {
                             chartRef.data.datasets[0].data[idx] -= amount;
                             if (chartRef.data.datasets[0].data[idx] < 0) chartRef.data.datasets[0].data[idx] = 0;
                             chartRef.update();
                         }
                     }
                    
                    setTimeout(() => {
                        tr.style.transition = 'all 0.3s ease';
                        tr.style.transform = 'translateX(-100%)';
                        setTimeout(() => tr.remove(), 300);
                    }, 500);
                    
                    showToast('Трата скрыта', 'info');
                });
                         }
             tbody.appendChild(newRow);
         });
         
         // Reinitialize inline editing for new rows
         makeEditable();
         
         // Show results count
         const resultsCount = filteredRows.length;
         const totalCount = originalTableRows.length;
         if (resultsCount !== totalCount) {
             showToast('Показано ' + resultsCount + ' из ' + totalCount + ' трат', 'info', 2000);
         }
     }
    
    // Event listeners for search and filter
    document.addEventListener('DOMContentLoaded', function() {
        const searchExpenses = document.getElementById('searchExpenses');
        const filterCategory = document.getElementById('filterCategory');
        const sortExpenses = document.getElementById('sortExpenses');
        const clearFilters = document.getElementById('clearFilters');
        
        if (searchExpenses) {
            searchExpenses.addEventListener('input', filterAndSortTable);
        }
        if (filterCategory) {
            filterCategory.addEventListener('change', filterAndSortTable);
        }
        if (sortExpenses) {
            sortExpenses.addEventListener('change', filterAndSortTable);
        }
        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                if (searchExpenses) searchExpenses.value = '';
                if (filterCategory) filterCategory.value = '';
                if (sortExpenses) sortExpenses.value = 'date_desc';
                filterAndSortTable();
                showToast('Фильтры очищены', 'info');
            });
        }
    });

    /* === Inline Editing === */
    function makeEditable() {
        document.querySelectorAll('.editable').forEach(element => {
            element.addEventListener('click', function() {
                if (this.classList.contains('editing')) return;
                
                const field = this.dataset.field;
                const row = this.closest('tr');
                const expenseId = row.dataset.id;
                const currentValue = this.textContent.trim();
                
                // Remove currency symbol and extract number for amount field
                let editValue = currentValue;
                if (field === 'amount') {
                    editValue = currentValue.replace(/[^\d.,]/g, '').replace(',', '.');
                } else if (field === 'category') {
                    editValue = currentValue.replace(/.*\s/, ''); // Remove icon and spaces
                }
                
                this.classList.add('editing');
                
                let inputElement;
                if (field === 'category') {
                    inputElement = document.createElement('select');
                    inputElement.className = 'inline-select';
                    
                    // Add all available categories
                    const categories = window.allCategories || [];
                    categories.forEach(cat => {
                        const option = document.createElement('option');
                        option.value = cat;
                        option.textContent = cat;
                        if (cat === editValue) option.selected = true;
                        inputElement.appendChild(option);
                    });
                } else {
                    inputElement = document.createElement('input');
                    inputElement.className = 'inline-input';
                    inputElement.type = field === 'amount' ? 'number' : 'text';
                    inputElement.value = editValue;
                    if (field === 'amount') {
                        inputElement.step = '0.01';
                        inputElement.min = '0';
                    }
                }
                
                const actionsDiv = document.createElement('div');
                actionsDiv.className = 'edit-actions';
                
                const saveBtn = document.createElement('button');
                saveBtn.className = 'btn btn-primary';
                saveBtn.innerHTML = '<i class="fas fa-check"></i> Сохранить';
                
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'btn btn-secondary';
                cancelBtn.innerHTML = '<i class="fas fa-times"></i> Отмена';
                
                actionsDiv.appendChild(saveBtn);
                actionsDiv.appendChild(cancelBtn);
                
                // Replace content
                const originalContent = this.innerHTML;
                this.innerHTML = '';
                this.appendChild(inputElement);
                this.appendChild(actionsDiv);
                
                inputElement.focus();
                if (inputElement.type === 'text') {
                    inputElement.select();
                }
                
                // Save function
                const saveEdit = () => {
                    const newValue = inputElement.value.trim();
                    if (!newValue) {
                        showToast('Значение не может быть пустым', 'error');
                        return;
                    }
                    
                    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Сохранение...';
                    saveBtn.disabled = true;
                    cancelBtn.disabled = true;
                    
                                         const updateData = {};
                     updateData[field] = newValue;
                     updateData.id = expenseId;
                     
                     fetch(baseApiUrl + '&action=expense', {
                         method: 'PUT',
                         headers: {'Content-Type': 'application/json'},
                         body: JSON.stringify(updateData)
                     })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                                                         // Update display
                             let displayValue = newValue;
                             if (field === 'amount') {
                                 displayValue = '<strong>' + parseFloat(newValue).toFixed(2) + ' ₽</strong>';
                                 row.dataset.amount = newValue;
                             } else if (field === 'category') {
                                 displayValue = '<i class="fas fa-tag"></i> ' + newValue;
                                 row.dataset.category = newValue;
                             }
                            
                            this.innerHTML = displayValue;
                            this.classList.remove('editing');
                            
                            showToast('Изменения сохранены', 'success');
                            
                            // Update chart if category or amount changed
                            if (field === 'category' || field === 'amount') {
                                setTimeout(() => location.reload(), 1000);
                            }
                        } else {
                            showToast(data.message || 'Ошибка при сохранении', 'error');
                            this.innerHTML = originalContent;
                            this.classList.remove('editing');
                        }
                    })
                    .catch(error => {
                        showToast('Ошибка сети', 'error');
                        this.innerHTML = originalContent;
                        this.classList.remove('editing');
                    });
                };
                
                // Cancel function
                const cancelEdit = () => {
                    this.innerHTML = originalContent;
                    this.classList.remove('editing');
                };
                
                // Event listeners
                saveBtn.addEventListener('click', saveEdit);
                cancelBtn.addEventListener('click', cancelEdit);
                
                inputElement.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveEdit();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancelEdit();
                    }
                });
                
                // Handle click outside
                const handleClickOutside = (e) => {
                    if (!this.contains(e.target)) {
                        document.removeEventListener('click', handleClickOutside);
                        cancelEdit();
                    }
                };
                
                setTimeout(() => {
                    document.addEventListener('click', handleClickOutside);
                }, 100);
            });
        });
    }
    
    // Initialize inline editing on page load
    document.addEventListener('DOMContentLoaded', makeEditable);

    // Кнопка "Отметить все категории" для графика по периодам
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleAllCategories');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const chart = window.chartByPeriods;
                // Безопасная проверка структуры графика
                if (!chart || !chart.data || !Array.isArray(chart.data.datasets) ||
                    typeof chart.isDatasetVisible !== 'function' || typeof chart.setDatasetVisibility !== 'function') {
                    showToast('График ещё не построен или недоступен', 'error');
                    return;
                }
                // Проверяем, все ли скрыты
                const allHidden = chart.data.datasets.every((ds, i) => chart.isDatasetVisible(i) === false);
                chart.data.datasets.forEach((ds, i) => {
                    chart.setDatasetVisibility(i, allHidden ? true : false);
                });
                chart.update();
            });
        }
    });

    // Тогл "Развернуть/Свернуть все" для категорий
    document.addEventListener('DOMContentLoaded', function() {
        const toggleBtn = document.getElementById('toggleExpandAllBtn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const headers = document.querySelectorAll('.category-header');
                // Если хотя бы одна не раскрыта — раскрыть все, иначе свернуть все
                const anyCollapsed = Array.from(headers).some(h => !h.classList.contains('expanded'));
                headers.forEach(header => {
                    header.classList.toggle('expanded', anyCollapsed);
                    header.nextElementSibling.classList.toggle('expanded', anyCollapsed);
                });
                toggleBtn.textContent = anyCollapsed ? 'Свернуть все' : 'Развернуть все';
            });
        }
    });