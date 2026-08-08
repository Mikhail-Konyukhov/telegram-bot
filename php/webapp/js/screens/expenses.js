/** Экран «Траты»: поиск, фильтр по категории, сортировка, список по дням. */

import { api } from '../api.js';
import { h, clear, skeleton, empty, errorBanner, toast, sheet, swipeable } from '../ui.js';
import { state, refresh } from '../store.js';
import { amount, dayLabel, time, isoDate, parseTs } from '../format.js';
import { emoji } from '../categories.js';
import { haptic, confirm } from '../tg.js';
import { openExpenseEditor, openExpenseForm } from './expense-form.js';

const PAGE = 300;

const SORTS = {
  date_desc: { label: 'Сначала новые', compare: (a, b) => parseTs(b.ts) - parseTs(a.ts) },
  date_asc: { label: 'Сначала старые', compare: (a, b) => parseTs(a.ts) - parseTs(b.ts) },
  amount_desc: { label: 'Сумма по убыванию', compare: (a, b) => b.amount - a.amount },
  amount_asc: { label: 'Сумма по возрастанию', compare: (a, b) => a.amount - b.amount },
  name_asc: { label: 'По названию', compare: (a, b) => String(a.name).localeCompare(String(b.name), 'ru') },
};

let query = '';
let sortKey = 'date_desc';
let shown = PAGE;

function matches(expense) {
  if (state.categoryFilter && expense.category !== state.categoryFilter) return false;
  if (!query) return true;
  return String(expense.name).toLowerCase().includes(query);
}

function groupByDay(items) {
  const groups = new Map();

  items.forEach((expense) => {
    const key = isoDate(parseTs(expense.ts));
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(expense);
  });

  return [...groups.values()];
}

function expenseRow(expense, onChanged) {
  const row = h('div', {
    class: 'item',
    onclick: () => openExpenseEditor(expense),
  }, [
    h('span', { class: 'emoji', text: emoji(expense.category) }),
    h('div', { class: 'body' }, [
      h('div', { class: 'title', text: expense.name }),
      h('div', { class: 'sub', text: `${expense.category} · ${time(expense.ts)}` }),
    ]),
    h('span', { class: 'value num', text: amount(expense.amount) }),
  ]);

  return swipeable(row, {
    label: 'Удалить',
    onAction: async () => {
      if (!await confirm(`Удалить «${expense.name}»?`)) return;
      try {
        await api.deleteExpense(expense.id);
        haptic('notification', 'success');
        onChanged();
      } catch (e) {
        toast(e.message, 'error');
      }
    },
  });
}

function searchBar(onInput) {
  const input = h('input', {
    class: 'field',
    type: 'search',
    placeholder: '🔍 Поиск по названию',
    value: query,
  });

  let timer = null;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      query = input.value.trim().toLowerCase();
      shown = PAGE;
      onInput();
    }, 200);
  });

  const sortButton = h('button', {
    class: 'chip',
    text: '⇅',
    onclick: () => {
      const close = sheet('Сортировка', Object.entries(SORTS).map(([key, sort]) => h('button', {
        class: 'btn secondary',
        style: 'margin-bottom:8px',
        text: (key === sortKey ? '✓ ' : '') + sort.label,
        onclick: () => {
          sortKey = key;
          close();
          onInput();
        },
      })));
    },
  });

  return h('div', { style: 'display:flex;gap:8px;align-items:flex-start' }, [
    h('div', { style: 'flex:1' }, [input]),
    sortButton,
  ]);
}

function categoryChips(items, onPick) {
  const present = [...new Set(items.map((expense) => expense.category))];

  return h('div', { class: 'chips' }, [
    h('button', {
      class: 'chip',
      'aria-pressed': state.categoryFilter ? 'false' : 'true',
      text: 'Все',
      onclick: () => {
        state.categoryFilter = null;
        haptic('selection');
        onPick();
      },
    }),
    ...present.map((category) => h('button', {
      class: 'chip',
      'aria-pressed': state.categoryFilter === category ? 'true' : 'false',
      text: `${emoji(category)} ${category}`,
      onclick: () => {
        state.categoryFilter = state.categoryFilter === category ? null : category;
        haptic('selection');
        onPick();
      },
    })),
  ]);
}

async function render(root) {
  const body = h('div', {}, [skeleton(4)]);
  root.append(body);

  let all;
  try {
    all = await api.expenses(state.period.start, state.period.end);
  } catch (e) {
    clear(body).append(errorBanner(e.message, refresh));
    return;
  }

  const items = all.expenses || [];

  const draw = () => {
    clear(body);
    body.append(searchBar(draw), categoryChips(items, draw));

    const filtered = items.filter(matches).sort(SORTS[sortKey].compare);

    if (!filtered.length) {
      body.append(empty(
        '🗒️',
        items.length ? 'Ничего не найдено' : 'За этот период трат нет',
        items.length ? null : h('button', { class: 'btn', text: 'Добавить трату', onclick: () => openExpenseForm() }),
      ));
      return;
    }

    const page = filtered.slice(0, shown);

    groupByDay(page).forEach((group) => {
      const dayTotal = group.reduce((sum, expense) => sum + Number(expense.amount), 0);

      body.append(h('div', { class: 'day-header' }, [
        h('span', { text: dayLabel(group[0].ts) }),
        h('span', { class: 'num', text: amount(dayTotal) }),
      ]));
      body.append(h('div', { class: 'list' }, group.map((expense) => expenseRow(expense, refresh))));
    });

    if (filtered.length > shown) {
      body.append(h('button', {
        class: 'btn secondary',
        text: `Показать ещё (${filtered.length - shown})`,
        onclick: () => {
          shown += PAGE;
          draw();
        },
      }));
    }
  };

  draw();
}

export default { render };
