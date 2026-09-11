/**
 * Адаптер над Telegram.WebApp.
 *
 * Проверяется одно: кнопки в шапке Telegram переживают закрытие приложения, а
 * их обработчики — нет. Отсюда «назад видна и ничего не делает», и отсюда же
 * требование снимать прежний обработчик перед установкой нового.
 *
 * tg.js читает window.Telegram на уровне модуля, поэтому окружение ставится до
 * импорта, а сам модуль грузится заново на каждый тест — через уникальный
 * query-string, иначе Node отдаст закэшированный экземпляр.
 */

import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

let reload = 0;

function fakeButton() {
  return {
    handlers: [],
    visible: false,
    onClick(handler) { this.handlers.push(handler); },
    offClick(handler) { this.handlers = this.handlers.filter((h) => h !== handler); },
    show() { this.visible = true; },
    hide() { this.visible = false; },
    setText() {},
    enable() {},
    showProgress() {},
    hideProgress() {},
  };
}

function fakeWebApp() {
  return {
    initData: 'query_id=fake',
    ready() {},
    expand() {},
    onEvent() {},
    isVersionAtLeast: () => true,
    disableVerticalSwipes() {},
    setHeaderColor() {},
    BackButton: fakeButton(),
    MainButton: fakeButton(),
  };
}

/** @returns {Promise<{tg: object, webApp: object}>} */
async function load(webApp) {
  globalThis.window = { Telegram: { WebApp: webApp } };
  reload += 1;

  return { tg: await import(`./tg.js?reload=${reload}`), webApp };
}

describe('backButton', () => {
  let webApp;

  beforeEach(() => {
    webApp = fakeWebApp();
  });

  it('снимает прежний обработчик, а не копит их', async () => {
    const { tg } = await load(webApp);
    const first = () => {};
    const second = () => {};

    tg.backButton.show(first);
    tg.backButton.show(second);

    assert.deepEqual(webApp.BackButton.handlers, [second]);
  });

  it('после hide не остаётся ни обработчика, ни кнопки', async () => {
    const { tg } = await load(webApp);

    tg.backButton.show(() => {});
    tg.backButton.hide();

    assert.deepEqual(webApp.BackButton.handlers, []);
    assert.equal(webApp.BackButton.visible, false);
  });

  /**
   * Пользователь закрыл приложение с открытым шитом: Telegram запомнил кнопку,
   * а обработчик умер вместе со страницей. При следующем открытии «назад» была
   * видна и не работала.
   */
  it('init гасит кнопку, оставшуюся от прошлого запуска', async () => {
    webApp.BackButton.visible = true;
    const { tg } = await load(webApp);

    tg.init();

    assert.equal(webApp.BackButton.visible, false);
    assert.equal(webApp.MainButton.visible, false);
  });
});

describe('вне Telegram', () => {
  it('кнопки не падают в обычном браузере', async () => {
    globalThis.window = {};
    reload += 1;
    const tg = await import(`./tg.js?reload=${reload}`);

    assert.doesNotThrow(() => {
      tg.init();
      tg.backButton.show(() => {});
      tg.backButton.hide();
    });
  });
});
