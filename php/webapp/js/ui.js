/** Мини-слой над DOM: элементы, тосты, шиты, состояния загрузки. */

import { haptic, backButton } from './tg.js';

/**
 * Создаёт элемент. Текст всегда идёт через textContent, поэтому названия трат
 * и категорий не могут превратиться в разметку.
 *
 * @param {string} tag
 * @param {Object} attrs class/text/onclick/произвольные атрибуты
 * @param {Array|Node|string} children
 * @returns {HTMLElement}
 */
export function h(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);

  Object.entries(attrs).forEach(([name, value]) => {
    if (value === null || value === undefined || value === false) return;
    if (name === 'class') node.className = value;
    else if (name === 'text') node.textContent = value;
    else if (name.startsWith('on')) node.addEventListener(name.slice(2).toLowerCase(), value);
    else node.setAttribute(name, value === true ? '' : String(value));
  });

  [].concat(children).forEach((child) => {
    if (child === null || child === undefined || child === false) return;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  });

  return node;
}

export function clear(node) {
  node.replaceChildren();
  return node;
}

export function toast(message, type = 'info') {
  const box = document.getElementById('toasts');
  const node = h('div', { class: `toast ${type}`, text: message });
  box.append(node);
  setTimeout(() => node.remove(), 3500);
}

export function skeleton(count = 3) {
  return h('div', {}, Array.from({ length: count }, () => h('div', { class: 'skeleton' })));
}

export function empty(emoji, title, action) {
  return h('div', { class: 'empty' }, [
    h('span', { class: 'emoji', text: emoji }),
    h('div', { text: title }),
    action || null,
  ]);
}

/** Сетевая ошибка показывается баннером с «Повторить», а не тостом. */
export function errorBanner(message, onRetry) {
  return h('div', { class: 'error-banner' }, [
    h('div', { text: message }),
    h('button', { class: 'btn secondary', onclick: onRetry, text: 'Повторить' }),
  ]);
}

/**
 * Модальный шит снизу. Возвращает функцию закрытия.
 *
 * @param {string} title
 * @param {Node|Node[]} content
 * @param {Function} [onClose]
 * @returns {Function}
 */
export function sheet(title, content, onClose) {
  const backdrop = h('div', { class: 'sheet-backdrop' });
  const panel = h('div', { class: 'sheet' }, [
    h('div', { class: 'grabber' }),
    h('h3', { text: title }),
    ...[].concat(content),
  ]);

  const close = () => {
    backdrop.remove();
    backButton.hide();
    if (onClose) onClose();
  };

  backdrop.append(panel);
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) close();
  });
  document.body.append(backdrop);
  backButton.show(close);

  return close;
}

/**
 * Оборачивает строку списка в свайп-контейнер с одним действием справа.
 *
 * @param {HTMLElement} row
 * @param {{label: string, onAction: Function}} action
 * @returns {HTMLElement}
 */
export function swipeable(row, action) {
  const wrap = h('div', { class: 'swipe' }, [
    h('div', { class: 'actions' }, [
      h('button', {
        text: action.label,
        onclick: (e) => {
          e.stopPropagation();
          action.onAction();
        },
      }),
    ]),
    row,
  ]);

  const WIDTH = 84;
  let startX = 0;
  let open = false;

  row.addEventListener('touchstart', (e) => {
    startX = e.touches[0].clientX;
  }, { passive: true });

  row.addEventListener('touchmove', (e) => {
    const delta = e.touches[0].clientX - startX;
    const shift = Math.max(-WIDTH, Math.min(0, (open ? -WIDTH : 0) + delta));
    row.style.transform = `translateX(${shift}px)`;
  }, { passive: true });

  row.addEventListener('touchend', () => {
    const shift = new DOMMatrix(getComputedStyle(row).transform).m41;
    open = shift < -WIDTH / 2;
    row.style.transform = `translateX(${open ? -WIDTH : 0}px)`;
    if (open) haptic('selection');
  });

  return wrap;
}
