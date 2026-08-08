/** Экран «Лимиты»: общий лимит кольцом, лимиты по категориям, установка. */

import { api } from '../api.js';
import { h, clear, skeleton, errorBanner, toast, sheet } from '../ui.js';
import { state, refresh } from '../store.js';
import { amount, percent } from '../format.js';
import { emoji } from '../categories.js';
import { haptic, confirm, mainButton } from '../tg.js';

const PRESETS = [1000, 5000, 10000];

function level(share) {
  if (share >= 100) return { css: 'over', color: 'var(--danger)' };
  if (share >= 80) return { css: 'warn', color: 'var(--warn)' };
  return { css: '', color: 'var(--ok)' };
}

/**
 * Шит установки лимита.
 *
 * @param {string|null} category null — общий лимит
 * @param {number} current Текущее значение, 0 если лимита нет
 */
function openLimitEditor(category, current) {
  const input = h('input', {
    class: 'amount-input num',
    type: 'text',
    inputmode: 'decimal',
    value: current ? String(current) : '',
    placeholder: '0',
  });

  const save = async () => {
    const value = parseFloat(String(input.value).replace(',', '.'));
    if (!(value > 0)) {
      toast('Введите сумму больше нуля', 'error');
      return;
    }

    try {
      await api.setLimit(category, value);
      haptic('notification', 'success');
      close();
      refresh();
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  const remove = async () => {
    if (!await confirm('Убрать лимит?')) return;
    try {
      await api.deleteLimit(category);
      haptic('notification', 'success');
      close();
      refresh();
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  const content = [
    input,
    h('div', { class: 'chips', style: 'justify-content:center' }, PRESETS.map((preset) => h('button', {
      class: 'chip',
      text: '+' + amount(preset),
      onclick: () => {
        input.value = String((parseFloat(String(input.value).replace(',', '.')) || 0) + preset);
        haptic('selection');
      },
    }))),
    h('button', { class: 'btn', text: 'Сохранить', onclick: save }),
    current
      ? h('button', { class: 'btn danger', style: 'margin-top:8px', text: 'Убрать лимит', onclick: remove })
      : null,
  ].filter(Boolean);

  const close = sheet(category ? `Лимит: ${category}` : 'Общий лимит', content, () => mainButton.hide());

  input.focus();
}

function globalCard(global) {
  if (!global) {
    return h('div', { class: 'card' }, [
      h('div', { class: 'row-between' }, [
        h('div', {}, [
          h('strong', { text: 'Общий лимит' }),
          h('div', { class: 'hint', text: 'Не задан' }),
        ]),
        h('button', { class: 'chip', text: 'Задать', onclick: () => openLimitEditor(null, 0) }),
      ]),
    ]);
  }

  const spent = Number(global.spent) || 0;
  const limit = Number(global.limit) || 0;
  const share = limit > 0 ? (spent / limit) * 100 : 0;
  const state_ = level(share);

  return h('div', { class: 'card', style: 'text-align:center' }, [
    h('div', {
      class: 'ring',
      style: `--share:${Math.min(share, 100)};--ring-color:${state_.color}`,
    }, [h('span', { class: 'share num', text: percent(share) })]),
    h('strong', { text: 'Общий лимит' }),
    h('div', { class: 'hint num', text: `${amount(spent)} из ${amount(limit)} за 30 дней` }),
    h('button', {
      class: 'btn secondary',
      style: 'margin-top:12px',
      text: 'Изменить',
      onclick: () => openLimitEditor(null, limit),
    }),
  ]);
}

function limitRow(entry) {
  const spent = Number(entry.spent) || 0;
  const limit = Number(entry.limit) || 0;
  const share = limit > 0 ? (spent / limit) * 100 : 0;

  return h('div', {
    class: 'item',
    onclick: () => openLimitEditor(entry.category, limit),
  }, [
    h('span', { class: 'emoji', text: emoji(entry.category) }),
    h('div', { class: 'body' }, [
      h('div', { class: 'title', text: entry.category }),
      h('div', { class: `progress ${level(share).css}` }, [
        h('i', { style: `width:${Math.min(share, 100)}%` }),
      ]),
      h('div', { class: 'sub num', text: `${amount(spent)} / ${amount(limit)}` }),
    ]),
  ]);
}

async function render(root) {
  const body = h('div', {}, [skeleton(2)]);
  root.append(body);

  let data;
  try {
    data = await api.limits();
  } catch (e) {
    clear(body).append(errorBanner(e.message, refresh));
    return;
  }

  clear(body);
  body.append(globalCard(data.global));

  const withLimit = data.categories || [];
  if (withLimit.length) {
    body.append(h('div', { class: 'section-title', text: 'По категориям' }));
    body.append(h('div', { class: 'list' }, withLimit.map(limitRow)));
  }

  const limited = new Set(withLimit.map((entry) => entry.category));
  const free = state.categories.filter((category) => !limited.has(category));

  if (free.length) {
    body.append(h('div', { class: 'section-title', text: 'Без лимита' }));
    body.append(h('div', { class: 'list' }, free.map((category) => h('div', {
      class: 'item',
      onclick: () => openLimitEditor(category, 0),
    }, [
      h('span', { class: 'emoji', text: emoji(category) }),
      h('div', { class: 'body' }, [h('div', { class: 'title', text: category })]),
      h('span', { class: 'value', text: '+' }),
    ]))));
  }
}

export default { render };
