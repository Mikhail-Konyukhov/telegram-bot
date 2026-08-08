/** Обёртки над Chart.js: цвета берутся из активной темы Telegram. */

import { color as categoryColor } from './categories.js';
import { amount } from './format.js';

function themeColor(name, fallback) {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name);
  return value.trim() || fallback;
}

function baseOptions() {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.label || ctx.dataset.label}: ${amount(ctx.parsed.y ?? ctx.parsed)}`,
        },
      },
    },
  };
}

/**
 * Кольцевая диаграмма по категориям.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {Object<string, number>} byCategory
 * @param {Function} onSelect Вызывается при тапе по сектору
 */
export function doughnut(canvas, byCategory, onSelect) {
  if (!window.Chart) return null;

  const labels = Object.keys(byCategory);
  const values = labels.map((label) => Number(byCategory[label]));

  return new window.Chart(canvas, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map(categoryColor),
        borderWidth: 0,
      }],
    },
    options: {
      ...baseOptions(),
      cutout: '62%',
      onClick: (_event, elements) => {
        if (elements.length && onSelect) onSelect(labels[elements[0].index]);
      },
    },
  });
}

/**
 * Столбцы по периодам с накоплением по категориям.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {Array<{label: string, categories: Object}>} rows
 */
export function stacked(canvas, rows) {
  if (!window.Chart) return null;

  const categories = [...new Set(rows.flatMap((row) => Object.keys(row.categories || {})))];
  const grid = themeColor('--separator', 'rgba(128,128,128,.2)');
  const text = themeColor('--hint', '#8e8e93');

  return new window.Chart(canvas, {
    type: 'bar',
    data: {
      labels: rows.map((row) => row.label),
      datasets: categories.map((category) => ({
        label: category,
        data: rows.map((row) => Number((row.categories || {})[category] || 0)),
        backgroundColor: categoryColor(category),
        borderRadius: 3,
      })),
    },
    options: {
      ...baseOptions(),
      plugins: {
        ...baseOptions().plugins,
        tooltip: {
          mode: 'index',
          filter: (ctx) => ctx.parsed.y > 0,
          callbacks: {
            label: (ctx) => `${ctx.dataset.label}: ${amount(ctx.parsed.y)}`,
            footer: (items) => 'Итого: ' + amount(items.reduce((sum, i) => sum + i.parsed.y, 0)),
          },
        },
      },
      scales: {
        x: { stacked: true, grid: { display: false }, ticks: { color: text } },
        y: {
          stacked: true,
          grid: { color: grid },
          ticks: { color: text, callback: (value) => amount(value) },
        },
      },
    },
  });
}
