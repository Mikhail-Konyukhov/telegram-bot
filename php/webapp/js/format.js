/** Форматирование денег и дат. */

const money = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
const moneyPrecise = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dayMonth = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long' });
const dayMonthShort = new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit' });
const timeOnly = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' });

export function amount(value) {
  const n = Number(value) || 0;
  // Копейки показываем только когда они есть — «300 ₽» читается лучше «300,00 ₽».
  return (Number.isInteger(n) ? money.format(n) : moneyPrecise.format(n)) + ' ₽';
}

export function percent(value) {
  return Math.round(Number(value) || 0) + '%';
}

/** Локальный YYYY-MM-DD. Через toISOString() получался сдвиг на день. */
export function isoDate(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** MySQL DATETIME парсится не всеми движками — приводим к ISO вручную. */
export function parseTs(ts) {
  return new Date(String(ts).replace(' ', 'T'));
}

export function time(ts) {
  return timeOnly.format(parseTs(ts));
}

export function shortDate(ts) {
  return dayMonthShort.format(parseTs(ts));
}

/** Заголовок группы в списке трат: «Сегодня», «Вчера» или «5 августа». */
export function dayLabel(ts) {
  const date = parseTs(ts);
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);

  if (isoDate(date) === isoDate(today)) return 'Сегодня';
  if (isoDate(date) === isoDate(yesterday)) return 'Вчера';
  return dayMonth.format(date);
}

export function periodLabel(start, end) {
  return `${dayMonthShort.format(new Date(start))} — ${dayMonthShort.format(new Date(end))}`;
}

/**
 * Границы преднастроенных периодов.
 *
 * @param {'today'|'week'|'month'|'year'} preset
 * @returns {{start: string, end: string}}
 */
export function presetRange(preset) {
  const end = new Date();
  const start = new Date();

  if (preset === 'today') {
    // start уже сегодня
  } else if (preset === 'week') {
    start.setDate(end.getDate() - 6);
  } else if (preset === 'year') {
    start.setFullYear(end.getFullYear() - 1);
    start.setDate(start.getDate() + 1);
  } else {
    start.setMonth(end.getMonth() - 1);
    start.setDate(start.getDate() + 1);
  }

  return { start: isoDate(start), end: isoDate(end) };
}
