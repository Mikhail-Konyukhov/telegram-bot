/** Экран «Обзор»: сумма за период, лимит, разбивка по категориям, динамика. */

import { api } from '../api.js';
import { h, clear, skeleton, empty, errorBanner, toast } from '../ui.js';
import { state, refresh } from '../store.js';
import { amount, percent, periodLabel, dayLabel, time } from '../format.js';
import { emoji, color } from '../categories.js';
import { doughnut, stacked } from '../charts.js';
import { haptic } from '../tg.js';
import { openExpenseEditor } from './expense-form.js';

/** Глубина «Динамики»: варианты и дефолт (второй, средний) для каждого шага. */
const DYNAMICS = {
  day: { label: 'По дням', unit: 'дн', counts: [7, 14, 30] },
  week: { label: 'По неделям', unit: 'нед', counts: [4, 8, 12] },
  month: { label: 'По месяцам', unit: 'мес', counts: [3, 6, 12] },
};

// Выбор переживает перерисовку экрана (сохранение траты, смена периода).
let dynamicsType = 'month';
let dynamicsCount = DYNAMICS.month.counts[1];

function hero(data) {
  const previous = Number(data.previous_total) || 0;
  const total = Number(data.total) || 0;

  let delta = null;
  if (previous > 0) {
    const change = Math.round(((total - previous) / previous) * 100);
    delta = h('div', {
      class: `delta ${change > 0 ? 'up' : 'down'}`,
      text: `${change > 0 ? '↑' : '↓'} ${Math.abs(change)}% к прошлому периоду`,
    });
  }

  return h('div', { class: 'card hero' }, [
    h('div', { class: 'total num', text: amount(total) }),
    delta,
    h('div', { class: 'hint', text: `${data.count} трат · в среднем ${amount(data.average)}` }),
  ]);
}

function globalLimit(limits) {
  if (!limits.global) return null;

  const spent = Number(limits.global.spent) || 0;
  const limit = Number(limits.global.limit) || 0;
  const share = limit > 0 ? (spent / limit) * 100 : 0;
  const level = share >= 100 ? 'over' : share >= 80 ? 'warn' : '';
  const left = limit - spent;

  return h('div', { class: 'card' }, [
    h('div', { class: 'row-between' }, [
      h('strong', { text: 'Общий лимит' }),
      h('span', { class: 'num', text: percent(share) }),
    ]),
    h('div', { class: `progress ${level}` }, [h('i', { style: `width:${Math.min(share, 100)}%` })]),
    h('div', {
      class: 'hint num',
      text: left >= 0
        ? `${amount(spent)} из ${amount(limit)} · осталось ${amount(left)}`
        : `${amount(spent)} из ${amount(limit)} · перерасход ${amount(-left)}`,
    }),
  ]);
}

function categoryBreakdown(byCategory, total) {
  const entries = Object.entries(byCategory);
  if (!entries.length) return null;

  const canvas = h('canvas');
  const chart = h('div', { class: 'card' }, [
    h('div', { class: 'chart-box' }, [canvas]),
  ]);

  // Chart.js рисует только после вставки канваса в документ.
  queueMicrotask(() => doughnut(canvas, byCategory, (category) => {
    state.categoryFilter = category;
    location.hash = '#/expenses';
  }));

  const rows = entries.slice(0, 5).map(([category, value]) => h('div', {
    class: 'item',
    onclick: () => {
      state.categoryFilter = category;
      location.hash = '#/expenses';
    },
  }, [
    h('span', { class: 'emoji', text: emoji(category) }),
    h('div', { class: 'body' }, [
      h('div', { class: 'title', text: category }),
      h('div', { class: 'progress' }, [
        h('i', { style: `width:${total ? (value / total) * 100 : 0}%;background:${color(category)}` }),
      ]),
    ]),
    h('span', { class: 'value num', text: amount(value) }),
  ]));

  return h('div', {}, [
    h('div', { class: 'section-title', text: 'По категориям' }),
    chart,
    h('div', { class: 'list' }, rows),
    entries.length > 5
      ? h('button', {
        class: 'btn secondary',
        text: `Показать все (${entries.length})`,
        onclick: () => { location.hash = '#/expenses'; },
      })
      : null,
  ]);
}

function recentExpenses(items) {
  if (!items.length) return null;

  return h('div', {}, [
    h('div', { class: 'section-title', text: 'Последние траты' }),
    h('div', { class: 'list' }, items.map((expense) => h('div', {
      class: 'item',
      onclick: () => openExpenseEditor(expense),
    }, [
      h('span', { class: 'emoji', text: emoji(expense.category) }),
      h('div', { class: 'body' }, [
        h('div', { class: 'title', text: expense.name }),
        h('div', { class: 'sub', text: `${dayLabel(expense.ts)}, ${time(expense.ts)}` }),
      ]),
      h('span', { class: 'value num', text: amount(expense.amount) }),
    ]))),
  ]);
}

/** Динамика по периодам подгружается отдельно — она не нужна для первого экрана. */
function dynamics() {
  const chart = h('div', { class: 'chart-box' });
  const steps = h('div', { class: 'segmented' });
  const counts = h('div', { class: 'chips' });

  // Быстрые переключения обгоняют друг друга: рисуем только последний ответ.
  let request = 0;

  function controls() {
    clear(steps).append(...Object.entries(DYNAMICS).map(([id, step]) => h('button', {
      'aria-pressed': id === dynamicsType ? 'true' : 'false',
      text: step.label,
      onclick: () => {
        if (id === dynamicsType) return;
        dynamicsType = id;
        dynamicsCount = step.counts[1];
        haptic('selection');
        controls();
        load();
      },
    })));

    const step = DYNAMICS[dynamicsType];
    clear(counts).append(...step.counts.map((count) => h('button', {
      class: 'chip',
      'aria-pressed': count === dynamicsCount ? 'true' : 'false',
      text: `${count} ${step.unit}`,
      onclick: () => {
        if (count === dynamicsCount) return;
        dynamicsCount = count;
        haptic('selection');
        controls();
        load();
      },
    })));
  }

  async function load() {
    const token = ++request;
    clear(chart).append(h('div', { class: 'skeleton', style: 'height:100%' }));

    let rows;
    try {
      rows = await api.analytics(dynamicsType, dynamicsCount);
    } catch (e) {
      if (token === request) clear(chart).append(h('div', { class: 'hint', text: 'Не удалось загрузить динамику' }));
      return;
    }

    if (token !== request) return;

    clear(chart);
    const canvas = h('canvas');
    chart.append(canvas);
    stacked(canvas, rows);
  }

  controls();
  load();

  return h('div', {}, [
    h('div', { class: 'section-title', text: 'Динамика' }),
    steps,
    counts,
    h('div', { class: 'card' }, [chart]),
  ]);
}

async function render(root) {
  const body = h('div', {}, [skeleton(3)]);
  root.append(body);

  let data;
  try {
    data = await api.overview(state.period.start, state.period.end);
  } catch (e) {
    clear(body).append(errorBanner(e.message, refresh));
    return;
  }

  clear(body);

  if (!data.count) {
    body.append(empty('🗒️', 'За этот период трат нет'));
    return;
  }

  // Часть блоков возвращает null (нет лимита, нет трат) — Element.append(null)
  // вставил бы строку «null», поэтому отсеиваем.
  [
    h('div', { class: 'hint', style: 'text-align:center;margin-bottom:8px', text: periodLabel(data.period.start, data.period.end) }),
    hero(data),
    globalLimit(data.limits),
    categoryBreakdown(data.by_category, Number(data.total)),
    dynamics(),
    recentExpenses(data.recent || []),
  ].filter(Boolean).forEach((node) => body.append(node));
}

export default { render };
