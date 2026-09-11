/**
 * Форматирование денег и дат в Mini App.
 *
 * Модуль чистый — ни DOM, ни сети, поэтому гоняется штатным `node --test`
 * без сборщика и без единой зависимости.
 *
 * Тесты намеренно не завязаны на часовой пояс машины: даты строятся локальными
 * конструкторами и сравниваются с локальными же ожиданиями. Абсолютных проверок
 * через toISOString() здесь нет — именно расхождение локального и UTC один раз
 * уже дало сдвиг на день, ради чего isoDate и появился.
 */

import { describe, it, mock, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import {
  amount,
  percent,
  isoDate,
  parseTs,
  time,
  shortDate,
  dayLabel,
  periodLabel,
  presetRange,
} from './format.js';

/** Intl в ru-RU разделяет тысячи неразрывным пробелом, а не обычным. */
const NBSP = ' ';

/** Фиксирует «сейчас» для функций, которые внутри вызывают new Date(). */
function freezeAt(year, month, day, hour = 12) {
  mock.timers.enable({ apis: ['Date'], now: new Date(year, month, day, hour).getTime() });
}

afterEach(() => mock.timers.reset());

describe('amount', () => {
  it('не показывает копейки, когда их нет', () => {
    assert.equal(amount(300), '300 ₽');
  });

  it('показывает копейки, когда они есть', () => {
    assert.equal(amount(300.5), '300,50 ₽');
  });

  it('разделяет тысячи неразрывным пробелом', () => {
    assert.equal(amount(10000), `10${NBSP}000 ₽`);
  });

  it('превращает мусор в ноль, а не в NaN', () => {
    assert.equal(amount(null), '0 ₽');
    assert.equal(amount(undefined), '0 ₽');
    assert.equal(amount('не число'), '0 ₽');
  });

  it('принимает число строкой — API отдаёт суммы строками', () => {
    assert.equal(amount('300'), '300 ₽');
  });
});

describe('percent', () => {
  it('округляет до целого', () => {
    assert.equal(percent(12.4), '12%');
    assert.equal(percent(12.5), '13%');
  });

  it('превращает мусор в ноль', () => {
    assert.equal(percent(undefined), '0%');
  });
});

describe('isoDate', () => {
  it('берёт локальную дату, а не UTC', () => {
    // Поздний вечер — момент, когда UTC уже перескочил на следующие сутки
    // в положительных зонах. Через toISOString() тут получался сдвиг на день.
    const date = new Date(2026, 7, 14, 23, 59);

    assert.equal(isoDate(date), '2026-08-14');
  });

  it('дополняет месяц и день нулями', () => {
    assert.equal(isoDate(new Date(2026, 0, 5)), '2026-01-05');
  });

  it('совпадает с локальными компонентами даты при любом часовом поясе', () => {
    const date = new Date(2026, 11, 31, 22, 15);
    const expected = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    assert.equal(isoDate(date), expected);
  });
});

describe('parseTs', () => {
  it('разбирает формат MySQL DATETIME', () => {
    const date = parseTs('2026-08-14 09:05:00');

    assert.equal(date.getFullYear(), 2026);
    assert.equal(date.getMonth(), 7);
    assert.equal(date.getDate(), 14);
    assert.equal(date.getHours(), 9);
    assert.equal(date.getMinutes(), 5);
  });

  it('трактует время как локальное, а не как UTC', () => {
    // Без этого траты, добавленные ночью, уезжали бы в соседний день.
    assert.equal(isoDate(parseTs('2026-08-14 23:30:00')), '2026-08-14');
  });
});

describe('time и shortDate', () => {
  it('показывают время двузначно', () => {
    assert.equal(time('2026-08-14 09:05:00'), '09:05');
  });

  it('показывают дату как дд.мм', () => {
    assert.equal(shortDate('2026-08-14 09:05:00'), '14.08');
  });
});

describe('dayLabel', () => {
  it('называет сегодняшнюю дату «Сегодня»', () => {
    freezeAt(2026, 7, 14);

    assert.equal(dayLabel('2026-08-14 09:00:00'), 'Сегодня');
  });

  it('называет вчерашнюю дату «Вчера»', () => {
    freezeAt(2026, 7, 14);

    assert.equal(dayLabel('2026-08-13 09:00:00'), 'Вчера');
  });

  it('старые даты показывает числом и месяцем', () => {
    freezeAt(2026, 7, 14);

    assert.equal(dayLabel('2026-08-05 09:00:00'), '5 августа');
  });

  it('не путает «Сегодня» с датой ровно сутки назад', () => {
    // Ночь — момент, когда наивное сравнение по разнице в миллисекундах врёт.
    freezeAt(2026, 7, 14, 0);

    assert.equal(dayLabel('2026-08-13 23:59:00'), 'Вчера');
  });

  it('первое число месяца остаётся «Вчера» на стыке месяцев', () => {
    freezeAt(2026, 8, 1);

    assert.equal(dayLabel('2026-08-31 12:00:00'), 'Вчера');
  });
});

describe('periodLabel', () => {
  it('склеивает границы через тире', () => {
    assert.equal(periodLabel('2026-08-01', '2026-08-31'), '01.08 — 31.08');
  });
});

describe('presetRange', () => {
  it('«сегодня» — это один день', () => {
    freezeAt(2026, 7, 14);

    assert.deepEqual(presetRange('today'), { start: '2026-08-14', end: '2026-08-14' });
  });

  it('«неделя» — семь дней вместе с сегодняшним', () => {
    freezeAt(2026, 7, 14);

    assert.deepEqual(presetRange('week'), { start: '2026-08-08', end: '2026-08-14' });
  });

  it('«месяц» — предыдущий месяц плюс сегодняшний день', () => {
    freezeAt(2026, 7, 14);

    assert.deepEqual(presetRange('month'), { start: '2026-07-15', end: '2026-08-14' });
  });

  it('«год» — год назад плюс сегодняшний день', () => {
    freezeAt(2026, 7, 14);

    assert.deepEqual(presetRange('year'), { start: '2025-08-15', end: '2026-08-14' });
  });

  it('неизвестный пресет ведёт себя как «месяц»', () => {
    freezeAt(2026, 7, 14);

    assert.deepEqual(presetRange('чепуха'), presetRange('month'));
  });

  // --- Закреплённые странности ---------------------------------------------
  //
  // Границы считаются арифметикой над Date, а она на переполнении месяца молча
  // переносит дату вперёд. Тесты ниже фиксируют результат как есть — если
  // поведение решат чинить, упадут ровно они.

  it('31-го числа «месяц» даёт окно внутри текущего месяца', () => {
    freezeAt(2026, 2, 31);

    // setMonth(февраль) от 31 марта даёт «31 февраля» → 3 марта, плюс день → 4-е.
    // Пользователь, выбравший «Месяц» 31 марта, получает 28 дней марта, а не месяц.
    assert.deepEqual(presetRange('month'), { start: '2026-03-04', end: '2026-03-31' });
  });

  it('29 февраля «год» промахивается на день', () => {
    freezeAt(2028, 1, 29);

    // 29 февраля минус год — несуществующая дата, Date переносит её на 1 марта,
    // и прибавленный день уводит начало периода на 2 марта вместо 1-го.
    assert.deepEqual(presetRange('year'), { start: '2027-03-02', end: '2028-02-29' });
  });
});
