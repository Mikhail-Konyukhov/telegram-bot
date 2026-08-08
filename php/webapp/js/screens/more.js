/** Экран «Ещё»: управление категориями и краткая справка. */

import { api } from '../api.js';
import { h, clear, skeleton, errorBanner, toast, swipeable } from '../ui.js';
import { state, refresh } from '../store.js';
import { emoji } from '../categories.js';
import { haptic, confirm } from '../tg.js';

function addCategoryForm(onAdded) {
  const input = h('input', { class: 'field', type: 'text', placeholder: 'Новая категория', maxlength: '100' });

  const submit = async () => {
    const name = input.value.trim();
    if (!name) return;

    try {
      await api.createCategory(name);
      input.value = '';
      haptic('notification', 'success');
      onAdded();
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submit();
  });

  return h('div', { style: 'display:flex;gap:8px;align-items:flex-start' }, [
    h('div', { style: 'flex:1' }, [input]),
    h('button', { class: 'chip', text: 'Добавить', onclick: submit }),
  ]);
}

function personalRow(category, onChanged) {
  const row = h('div', { class: 'item' }, [
    h('span', { class: 'emoji', text: emoji(category.name) }),
    h('div', { class: 'body' }, [h('div', { class: 'title', text: category.name })]),
  ]);

  return swipeable(row, {
    label: 'Удалить',
    onAction: async () => {
      if (!await confirm(`Удалить категорию «${category.name}»? Траты останутся.`)) return;
      try {
        await api.deleteCategory(category.name);
        haptic('notification', 'success');
        onChanged();
      } catch (e) {
        toast(e.message, 'error');
      }
    },
  });
}

function help() {
  return h('div', {}, [
    h('div', { class: 'section-title', text: 'Как пользоваться' }),
    h('div', { class: 'card' }, [
      h('p', { style: 'margin:0 0 10px', text: 'Отправьте боту сообщение вида «кофе 200» или «такси 300, обед 450» — категория определится автоматически.' }),
      h('p', { class: 'hint', style: 'margin:0', text: 'Команды: /app, /categories, /setlimit, /setgloballimit' }),
    ]),
  ]);
}

async function render(root) {
  const body = h('div', {}, [skeleton(2)]);
  root.append(body);

  let data;
  try {
    data = await api.categories();
  } catch (e) {
    clear(body).append(errorBanner(e.message, refresh));
    return;
  }

  state.categories = data.all_categories || [];

  const personal = data.personal_categories || [];
  const personalNames = new Set(personal.map((category) => category.name));
  const system = state.categories.filter((name) => !personalNames.has(name));

  clear(body);
  body.append(h('div', { class: 'section-title', text: 'Свои категории' }));
  body.append(addCategoryForm(refresh));

  if (personal.length) {
    body.append(h('div', { class: 'list' }, personal.map((category) => personalRow(category, refresh))));
  } else {
    body.append(h('div', { class: 'hint', style: 'padding:0 4px 8px', text: 'Пока нет — добавьте свою категорию выше.' }));
  }

  body.append(h('div', { class: 'section-title', text: 'Системные' }));
  body.append(h('div', { class: 'list' }, system.map((name) => h('div', { class: 'item' }, [
    h('span', { class: 'emoji', text: emoji(name) }),
    h('div', { class: 'body' }, [h('div', { class: 'title', text: name })]),
    h('span', { class: 'sub', text: 'системная' }),
  ]))));

  body.append(help());
}

export default { render };
