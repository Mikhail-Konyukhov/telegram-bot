/**
 * Общее состояние экранов.
 *
 * Вынесено из app.js отдельным модулем, чтобы экраны не импортировали app.js,
 * который импортирует их же.
 */

import { presetRange } from './format.js';

export const state = {
  preset: 'month',
  period: presetRange('month'),
  /** @type {string[]} Кеш списка категорий — нужен почти на каждом экране. */
  categories: [],
  /** @type {string|null} Фильтр экрана «Траты», выставляется тапом по графику. */
  categoryFilter: null,
};

let refreshHandler = () => {};

export function onRefresh(handler) {
  refreshHandler = handler;
}

/** Перерисовать текущий экран (после сохранения, удаления и т.п.). */
export function refresh() {
  refreshHandler();
}

export function setPeriod(preset, range) {
  state.preset = preset;
  state.period = range || presetRange(preset);
}
