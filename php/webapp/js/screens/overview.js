/** Экран «Обзор»: сумма за период, лимит, разбивка по категориям, динамика. */

import { api } from '../api.js';
import { h, clear, skeleton, empty, errorBanner, toast } from '../ui.js';
import { state, setPeriod, refresh } from '../store.js';
import { amount, percent, periodLabel, dayLabel, time } from '../format.js';
import { emoji, color } from '../categories.js';
import { doughnut, stacked } from '../charts.js';
import { haptic } from '../tg.js';
import { openExpenseEditor } from './expense-form.js';

const PRESETS = [
  { id: 'today', label: 'Сегодня' },
  { id: 'week', label: 'Неделя' },
  { id: 'month', label: 'Месяц' },
];

function periodSwitch() {
  return h('div', { class: 'segmented' }, PRESETS.map((preset) => h('button', {
    'aria-pressed': state.preset === preset.id ? 'true' : 'false',
    text: preset.label,
    onclick: () => {
      if (state.preset === preset.id) return;
      haptic('selection');
      setPeriod(preset.id);
      refresh();
    },
  })));
}

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
  const box = h('div', { class: 'card' }, [h('div', { class: 'chart-box' })]);
  const selector = h('div', { class: 'segmented' }, [
    ['day', 'По дням'],
    ['week', 'По неделям'],
    ['month', 'По месяцам'],
  ].map(([id, label], index) => h('button', {
    'aria-pressed': index === 2 ? 'true' : 'false',
    text: label,
    onclick: (e) => {
      selector.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', 'false'));
      e.currentTarget.setAttribute('aria-pressed', 'true');
      haptic('selection');
      load(id);
    },
  })));

  async function load(periodType) {
    const target = box.firstChild;
    clear(target).append(h('div', { class: 'skeleton' }));

    try {
      const rows = await api.analytics(periodType, 6);
      clear(target);
      const canvas = h('canvas');
      target.append(canvas);
      stacked(canvas, rows);
    } catch (e) {
      clear(target).append(h('div', { class: 'hint', text: 'Не удалось загрузить динамику' }));
    }
  }

  load('month');

  return h('div', {}, [
    h('div', { class: 'section-title', text: 'Динамика' }),
    selector,
    box,
  ]);
}

async function render(root) {
  root.append(periodSwitch());

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
