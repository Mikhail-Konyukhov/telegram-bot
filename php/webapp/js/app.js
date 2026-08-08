/** Точка входа: инициализация Telegram, таб-бар, роутинг по хешу. */

import * as tg from './tg.js';
import { h, clear, toast } from './ui.js';
import { state, onRefresh } from './store.js';
import { api } from './api.js';
import overview from './screens/overview.js';
import expenses from './screens/expenses.js';
import limits from './screens/limits.js';
import more from './screens/more.js';
import { openExpenseForm } from './screens/expense-form.js';

const TABS = [
  { id: 'overview', icon: '📊', label: 'Обзор', screen: overview, addButton: true },
  { id: 'expenses', icon: '📝', label: 'Траты', screen: expenses, addButton: true },
  { id: 'limits', icon: '🎯', label: 'Лимиты', screen: limits, addButton: false },
  { id: 'more', icon: '⚙️', label: 'Ещё', screen: more, addButton: false },
];

const root = document.getElementById('app');
const tabbar = document.getElementById('tabbar');

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

function renderScreen() {
  const tab = currentTab();

  renderTabbar(tab.id);
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
