/**
 * Клиент API.
 *
 * initData уходит заголовком, а не в query — так подпись не оседает в логах
 * веб-сервера и прокси.
 */

import { initData } from './tg.js';

const BASE = '../api.php';

export class ApiError extends Error {}

async function request(action, { method = 'GET', params = {}, body = null } = {}) {
  const query = new URLSearchParams({ action, ...params });

  let response;
  try {
    response = await fetch(`${BASE}?${query}`, {
      method,
      headers: {
        'X-Telegram-Init-Data': initData,
        ...(body ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    });
  } catch (e) {
    throw new ApiError('Нет связи с сервером');
  }

  let payload;
  try {
    payload = await response.json();
  } catch (e) {
    throw new ApiError('Сервер вернул не JSON');
  }

  if (!response.ok || !payload.success) {
    throw new ApiError(payload.error || `Ошибка ${response.status}`);
  }

  return payload.data;
}

export const api = {
  overview: (start, end) => request('overview', { params: { start_date: start, end_date: end } }),
  expenses: (start, end, category) =>
    request('expenses', { params: { start_date: start, end_date: end, ...(category ? { category } : {}) } }),
  categories: () => request('categories'),
  limits: () => request('limits'),
  suggestions: () => request('suggestions'),
  analytics: (periodType, periodsCount) =>
    request('analytics_by_period', { params: { period_type: periodType, periods_count: periodsCount } }),

  createExpense: (expense) => request('expense', { method: 'POST', body: expense }),
  updateExpense: (patch) => request('expense', { method: 'PUT', body: patch }),
  deleteExpense: (id) => request('expense', { method: 'DELETE', params: { id } }),

  createCategory: (name) => request('category', { method: 'POST', body: { name } }),
  deleteCategory: (name) => request('category', { method: 'DELETE', params: { name } }),

  setLimit: (category, amount) => request('limit', { method: 'POST', body: { category, amount } }),
  deleteLimit: (category) => request('limit', { method: 'DELETE', params: { category: category || '' } }),
};
