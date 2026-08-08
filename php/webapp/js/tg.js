/**
 * Адаптер над Telegram.WebApp.
 *
 * Все вызовы защищены от отсутствия API: приложение должно открываться и в
 * обычном браузере (для отладки), просто без нативных кнопок и хаптики.
 */

export const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

export const initData = tg ? tg.initData : '';

/** Клиент Telegram старее указанной версии не умеет часть методов. */
function since(version) {
  return tg && tg.isVersionAtLeast && tg.isVersionAtLeast(version);
}

export function init() {
  if (!tg) return;
  tg.ready();
  tg.expand();
  // Иначе вертикальный скролл списка тянет за собой закрытие приложения.
  if (since('7.7') && tg.disableVerticalSwipes) tg.disableVerticalSwipes();
  if (since('6.1') && tg.setHeaderColor) tg.setHeaderColor('secondary_bg_color');
}

export function onThemeChanged(handler) {
  if (tg) tg.onEvent('themeChanged', handler);
}

/** Высота, доступная под контент, с поправкой на клавиатуру. */
export function onViewportChanged(handler) {
  if (tg) tg.onEvent('viewportChanged', handler);
}

export function haptic(type, style) {
  if (!tg || !tg.HapticFeedback || !since('6.1')) return;
  const h = tg.HapticFeedback;
  if (type === 'selection') h.selectionChanged();
  else if (type === 'notification') h.notificationOccurred(style || 'success');
  else h.impactOccurred(style || 'light');
}

export function confirm(message) {
  return new Promise((resolve) => {
    if (tg && since('6.2') && tg.showConfirm) tg.showConfirm(message, resolve);
    else resolve(window.confirm(message));
  });
}

export function alert(message) {
  return new Promise((resolve) => {
    if (tg && since('6.2') && tg.showAlert) tg.showAlert(message, resolve);
    else {
      window.alert(message);
      resolve();
    }
  });
}

/** Нативная кнопка «назад» в шапке Telegram. */
export const backButton = {
  show(handler) {
    if (!tg || !tg.BackButton) return;
    this._handler = handler;
    tg.BackButton.onClick(handler);
    tg.BackButton.show();
  },
  hide() {
    if (!tg || !tg.BackButton) return;
    if (this._handler) tg.BackButton.offClick(this._handler);
    this._handler = null;
    tg.BackButton.hide();
  },
};

/** Главная кнопка внизу экрана: на списках «Добавить», в формах «Сохранить». */
export const mainButton = {
  show(text, handler) {
    if (!tg || !tg.MainButton) return;
    this.hide();
    this._handler = handler;
    tg.MainButton.setText(text);
    tg.MainButton.onClick(handler);
    tg.MainButton.show();
    tg.MainButton.enable();
  },
  progress(on) {
    if (!tg || !tg.MainButton) return;
    if (on) tg.MainButton.showProgress(false);
    else tg.MainButton.hideProgress();
  },
  hide() {
    if (!tg || !tg.MainButton) return;
    if (this._handler) tg.MainButton.offClick(this._handler);
    this._handler = null;
    tg.MainButton.hide();
    tg.MainButton.hideProgress();
  },
};

export function closingConfirmation(on) {
  if (!tg || !since('6.2')) return;
  if (on && tg.enableClosingConfirmation) tg.enableClosingConfirmation();
  if (!on && tg.disableClosingConfirmation) tg.disableClosingConfirmation();
}

export function close() {
  if (tg) tg.close();
}
