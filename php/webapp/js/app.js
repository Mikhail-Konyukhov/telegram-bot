/** Точка входа: инициализация Telegram, таб-бар, роутинг по хешу. */

import * as tg from './tg.js';
import { h, clear, toast, sheet } from './ui.js';
import { state, onRefresh, setPeriod } from './store.js';
import { isoDate, periodLabel } from './format.js';
import { api } from './api.js';
import overview from './screens/overview.js';
import expenses from './screens/expenses.js';
import limits from './screens/limits.js';
import more from './screens/more.js';
import { openExpenseForm } from './screens/expense-form.js';

// periodBar — экраны, содержимое которых зависит от выбранного периода.
const TABS = [
  { id: 'overview', icon: '📊', label: 'Обзор', screen: overview, addButton: true, periodBar: true },
  { id: 'expenses', icon: '📝', label: 'Траты', screen: expenses, addButton: true, periodBar: true },
  { id: 'limits', icon: '🎯', label: 'Лимиты', screen: limits, addButton: false, periodBar: false },
  { id: 'more', icon: '⚙️', label: 'Ещё', screen: more, addButton: false, periodBar: false },
];

const PRESETS = [
  { id: 'today', label: 'Сегодня' },
  { id: 'week', label: 'Неделя' },
  { id: 'month', label: 'Месяц' },
  { id: 'year', label: 'Год' },
];

const root = document.getElementById('app');
const tabbar = document.getElementById('tabbar');
const periodbar = document.getElementById('periodbar');

function currentTab() {
  const id = location.hash.replace('#/', '');
  return TABS.find((tab) => tab.id === id) || TABS[0];
}

function renderTabbar(activeId) {
  clear(tabbar);

  TABS.forEach((tab) => {
    tabbar.append(h('button', {
      'aria-current': tab.id === activeId ? 'true' : 'false',
      onclick: () => {
        if (tab.id !== activeId) tg.haptic('selection');
        location.hash = `#/${tab.id}`;
      },
    }, [
      h('span', { class: 'icon', text: tab.icon }),
      h('span', { text: tab.label }),
    ]));
  });
}

function pickPeriod(preset, range) {
  setPeriod(preset, range);
  tg.haptic('selection');
  renderScreen();
}

/** Произвольный диапазон: единственный способ добраться до старых трат. */
function openPeriodPicker() {
  const today = isoDate(new Date());
  const start = h('input', { class: 'field', type: 'date', value: state.period.start, max: today });
  const end = h('input', { class: 'field', type: 'date', value: state.period.end, max: today });

  const close = sheet('Свой период', [
    h('div', { class: 'hint', text: 'Начало' }),
    start,
    h('div', { class: 'hint', text: 'Конец' }),
    end,
    h('button', {
      class: 'btn',
      text: 'Показать',
      onclick: () => {
        if (!start.value || !end.value) return toast('Укажите обе даты', 'error');
        if (start.value > end.value) return toast('Начало периода позже конца', 'error');
        close();
        pickPeriod('custom', { start: start.value, end: end.value });
      },
    }),
  ]);
}

function renderPeriodbar(tab) {
  periodbar.hidden = !tab.periodBar;
  if (periodbar.hidden) return;

  const custom = state.preset === 'custom';

  clear(periodbar).append(h('div', { class: 'chips' }, [
    ...PRESETS.map((preset) => h('button', {
      class: 'chip',
      'aria-pressed': state.preset === preset.id ? 'true' : 'false',
      text: preset.label,
      onclick: () => {
        if (state.preset === preset.id) return;
        pickPeriod(preset.id);
      },
    })),
    h('button', {
      class: 'chip',
      'aria-pressed': custom ? 'true' : 'false',
      text: custom ? periodLabel(state.period.start, state.period.end) : 'Свой период',
      onclick: openPeriodPicker,
    }),
  ]));
}

function renderScreen() {
  const tab = currentTab();

  renderTabbar(tab.id);
  renderPeriodbar(tab);
  clear(root);
  root.scrollIntoView();

  if (tab.addButton) {
    tg.mainButton.show('＋ Добавить трату', () => openExpenseForm());
  } else {
    tg.mainButton.hide();
  }

  tab.screen.render(root);
}

/**
 * Список категорий нужен почти всем экранам, поэтому загружается один раз при
 * старте и лежит в общем состоянии.
 */
async function preloadCategories() {
  try {
    const data = await api.categories();
    state.categories = data.all_categories || [];
  } catch (e) {
    // Не блокирует запуск: экраны, которым категории нужны, перезапросят сами.
  }
}

function start() {
  tg.init();

  if (!tg.initData && tg.tg) {
    root.append(h('div', { class: 'error-banner', text: 'Приложение нужно открывать из Telegram.' }));
    return;
  }

  onRefresh(renderScreen);
  window.addEventListener('hashchange', renderScreen);
  tg.onThemeChanged(renderScreen);

  if (!location.hash) location.hash = '#/overview';

  preloadCategories().then(renderScreen);
}

window.addEventListener('error', (e) => toast(e.message || 'Непредвиденная ошибка', 'error'));

start();
