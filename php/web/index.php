<?php

/**
 * Вход веб-версии дашборда.
 *
 * Экраны те же, что в Mini App: страница грузит модули из `/webapp`, отличается
 * только вход. `?t=` — подписанная ссылка из бота (команда /web); она сразу
 * меняется на cookie и уходит из адреса, чтобы не осесть в истории браузера
 * и логах прокси. Без валидной cookie вместо приложения показывается заглушка:
 * пустой дашборд с 403 в консоли выглядел бы поломкой.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Cfg;
use App\Services\WebSession;

$botToken = (new Cfg())->getTelegramBotToken();

$token = (string)($_GET['t'] ?? '');
if ($token !== '') {
    $ledgerId = WebSession::verifyLink($token, $botToken);

    if ($ledgerId !== null) {
        setcookie(WebSession::COOKIE, WebSession::cookie($ledgerId, $botToken), [
            'expires'  => time() + WebSession::SESSION_TTL,
            // Не '/web/': эту же cookie предъявляет api.php.
            'path'     => '/',
            'httponly' => true,
            // Единственная защита от CSRF: Lax не отдаёт cookie в кросс-сайтовых
            // POST/PUT/DELETE, а GET-действия API только читают.
            'samesite' => 'Lax',
            'secure'   => ($_SERVER['HTTPS'] ?? '') !== ''
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
    }

    header('Location: ./');
    exit;
}

// Заглушка не должна залипнуть в кэше после входа.
header('Cache-Control: no-store');

$authorized = WebSession::verifyCookie((string)($_COOKIE[WebSession::COOKIE] ?? ''), $botToken) !== null;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Расходы</title>
<?php if ($authorized): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<?php endif; ?>
  <link rel="stylesheet" href="../webapp/css/app.css">
</head>
<body>
<?php if ($authorized): ?>
  <header id="periodbar" class="periodbar" hidden></header>
  <main id="app"></main>
  <nav id="tabbar" class="tabbar"></nav>
  <div id="toasts" class="toasts"></div>
  <script type="module" src="../webapp/js/app.js"></script>
<?php else: ?>
  <main id="app">
    <div class="empty">
      <span class="emoji">🔐</span>
      <div>Дашборд открывается по ссылке из бота</div>
      <div class="hint">Отправьте ему команду /web — ссылка придёт в чат и будет действовать неделю.</div>
    </div>
  </main>
<?php endif; ?>
</body>
</html>
