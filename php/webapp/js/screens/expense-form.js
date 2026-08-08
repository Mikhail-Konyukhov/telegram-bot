/** Добавление и редактирование траты. */

import { api } from '../api.js';
import { h, toast, sheet } from '../ui.js';
import { state, refresh } from '../store.js';
import { amount, isoDate, parseTs } from '../format.js';
import { emoji } from '../categories.js';
import { haptic, confirm, mainButton, closingConfirmation } from '../tg.js';

/** Значение для input[type=datetime-local] из MySQL-даты либо из «сейчас». */
function localDateTime(ts) {
  const date = ts ? parseTs(ts) : new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${isoDate(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function categoryPicker(selected, onPick) {
  const chips = h('div', { class: 'chips' }, state.categories.map((category) => h('button', {
    class: 'chip',
    'data-category': category,
    'aria-pressed': category === selected ? 'true' : 'false',
    text: `${emoji(category)} ${category}`,
    onclick: (e) => {
      chips.querySelectorAll('.chip').forEach((chip) => chip.setAttribute('aria-pressed', 'false'));
      e.currentTarget.setAttribute('aria-pressed', 'true');
      haptic('selection');
      onPick(category);
    },
  })));

  return chips;
}

/**
 * Подсказки частых позиций. Тап подставляет название и, если сумма ещё пустая,
 * типичную сумму — повторная покупка закрывается одним нажатием. Ничего не
 * отправляется: пользователь видит, что получилось, и правит при желании.
 */
function suggestionChips(nameInput, amountInput, onPick) {
  const box = h('div', { class: 'chips' });

  api.suggestions().then((data) => {
    (data.suggestions || []).forEach((item) => {
      box.append(h('button', {
        class: 'chip',
        onclick: () => {
          nameInput.value = item.name;
          if (!amountInput.value.trim()) {
            amountInput.value = String(Math.round(item.avg_amount));
          }
          haptic('selection');
          onPick(item);
        },
      }, [
        `${emoji(item.category)} ${item.name}`,
        h('span', { class: 'chip-hint', text: '~' + amount(item.avg_amount) }),
      ]));
    });
  }).catch(() => {
    // Подсказки необязательны — молча остаёмся без них.
  });

  return box;
}

/**
 * Открывает форму траты.
 *
 * @param {Object|null} expense null — создание новой
 */
function openForm(expense) {
  const editing = Boolean(expense);

  const amountInput = h('input', {
    class: 'amount-input num',
    type: 'text',
    inputmode: 'decimal',
    placeholder: '0',
    value: editing ? String(expense.amount).replace(/\.00$/, '') : '',
  });

  const nameInput = h('input', {
    class: 'field',
    type: 'text',
    placeholder: 'Название',
    maxlength: '255',
    value: editing ? expense.name : '',
  });

  const dateInput = h('input', {
    class: 'field',
    type: 'datetime-local',
    value: localDateTime(editing ? expense.ts : null),
  });

  let category = editing ? expense.category : (state.categories[0] || '');

  let dirty = false;
  const markDirty = () => {
    if (dirty) return;
    dirty = true;
    closingConfirmation(true);
  };
  [amountInput, nameInput, dateInput].forEach((field) => field.addEventListener('input', markDirty));

  // refresh() вызывает onClose шита — второй раз дёргать не нужно.
  const finish = () => {
    closingConfirmation(false);
    mainButton.hide();
    close();
  };

  const save = async () => {
    const value = parseFloat(String(amountInput.value).replace(',', '.'));
    const name = nameInput.value.trim();

    if (!(value > 0)) {
      toast('Введите сумму больше нуля', 'error');
      return;
    }
    if (!name) {
      toast('Введите название', 'error');
      return;
    }
    if (!category) {
      toast('Выберите категорию', 'error');
      return;
    }

    const payload = {
      name,
      category,
      amount: value,
      date: dateInput.value.replace('T', ' ') + ':00',
    };

    mainButton.progress(true);
    try {
      if (editing) await api.updateExpense({ id: expense.id, ...payload });
      else await api.createExpense(payload);
      haptic('notification', 'success');
      finish();
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      mainButton.progress(false);
    }
  };

  const remove = async () => {
    if (!await confirm(`Удалить «${expense.name}»?`)) return;
    try {
      await api.deleteExpense(expense.id);
      haptic('notification', 'success');
      finish();
    } catch (e) {
      toast(e.message, 'error');
    }
  };

  const content = [
    amountInput,
    !editing ? suggestionChips(nameInput, amountInput, (item) => {
      category = item.category;
      picker.querySelectorAll('.chip').forEach((chip) => {
        chip.setAttribute('aria-pressed', chip.dataset.category === item.category ? 'true' : 'false');
      });
    }) : null,
    nameInput,
    h('div', { class: 'section-title', text: 'Категория' }),
  ].filter(Boolean);

  const picker = categoryPicker(category, (picked) => { category = picked; markDirty(); });
  content.push(picker, dateInput);

  if (editing) {
    content.push(h('button', { class: 'btn danger', style: 'margin-top:8px', text: 'Удалить трату', onclick: remove }));
  }

  const close = sheet(editing ? 'Изменить трату' : 'Новая трата', content, () => {
    closingConfirmation(false);
    // Возвращаем экрану его собственную главную кнопку.
    refresh();
  });

  mainButton.show('Сохранить', save);

  if (!editing) amountInput.focus();
}

/**
 * Добавление всегда открывает структурированную форму: свободный ввод строкой
 * дешевле сделать в чате, не дожидаясь загрузки приложения.
 */
export function openExpenseForm() {
  openForm(null);
}

export function openExpenseEditor(expense) {
  openForm(expense);
}
