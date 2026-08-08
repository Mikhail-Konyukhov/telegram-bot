/**
 * Единый источник эмодзи и цветов категорий.
 *
 * Этой же картой красятся графики, точки в списке и чипы фильтра — раньше
 * палитра была продублирована в CSS и в двух местах в JS.
 */

const KNOWN = {
  'еда': { emoji: '🍎', color: '#e8663d' },
  'кафе и рестораны': { emoji: '🍔', color: '#f2994a' },
  'транспорт': { emoji: '🚕', color: '#2f80ed' },
  'жилье': { emoji: '🏠', color: '#9b51e0' },
  'коммуналка': { emoji: '💡', color: '#f2c94c' },
  'одежда': { emoji: '👕', color: '#eb5757' },
  'развлечения': { emoji: '🎬', color: '#bb6bd9' },
  'здоровье': { emoji: '💊', color: '#27ae60' },
  'гигиена': { emoji: '🧼', color: '#56ccf2' },
  'техника': { emoji: '💻', color: '#4f4f4f' },
  'спортпит': { emoji: '🏋️', color: '#219653' },
  'домашние животные': { emoji: '🐾', color: '#a2845e' },
  'товары для дома': { emoji: '🧺', color: '#6fcf97' },
};

/** Палитра для личных категорий пользователя — выбор детерминирован по имени. */
const FALLBACK = ['#2f80ed', '#eb5757', '#f2994a', '#27ae60', '#9b51e0', '#56ccf2', '#f2c94c', '#a2845e'];

function hash(text) {
  let h = 0;
  for (let i = 0; i < text.length; i += 1) {
    h = (h * 31 + text.charCodeAt(i)) | 0;
  }
  return Math.abs(h);
}

function key(name) {
  return String(name || '').trim().toLowerCase();
}

export function emoji(name) {
  const known = KNOWN[key(name)];
  return known ? known.emoji : '🏷️';
}

export function color(name) {
  const known = KNOWN[key(name)];
  return known ? known.color : FALLBACK[hash(key(name)) % FALLBACK.length];
}
