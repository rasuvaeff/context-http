# rasuvaeff/context-http

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-http/v)](https://packagist.org/packages/rasuvaeff/context-http)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-http/downloads)](https://packagist.org/packages/rasuvaeff/context-http)
[![Build](https://github.com/rasuvaeff/context-http/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-http/php)](https://packagist.org/packages/rasuvaeff/context-http)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Адаптеры PSR-15 и PSR-18 для [`rasuvaeff/context`](https://github.com/rasuvaeff/context):
дедлайн, отмена и correlation id в рамках запроса, передаваемые между сервисами.

> Пользуетесь AI-ассистентом? В [llms.txt](llms.txt) — компактный справочник по API, который можно передать модели.

## Требования

- PHP 8.3+
- `rasuvaeff/context` ^0.2
- `psr/http-message` ^2.0, `psr/http-factory` ^1.0, `psr/http-client` ^1.0,
  `psr/http-server-handler` ^1.0, `psr/http-server-middleware` ^1.0
- `guzzlehttp/guzzle` ^7 — только для `ContextClient` (необязательно)

## Установка

```bash
composer require rasuvaeff/context-http
```

## Ограничения

Адаптеры ограничивают, сколько запрос **ждёт**, но не прерывают уже идущую
работу. Матрица транспортов — в
[`rasuvaeff/context`](https://github.com/rasuvaeff/context/blob/master/README.ru.md#ограничения).

- **`ContextMiddleware` не останавливает handler.** Дедлайн замечается только
  там, где handler вызывает `assertActive()` (сам или через адаптер); маппинг
  в 504/499 применяется к исключению из этого вызова. Отключение клиента он
  тоже не обнаруживает — `499` появляется, только если ваш код отменил
  контроллер. Держите бюджет ниже собственного лимита сервера (PHP-FPM
  `request_terminate_timeout`, PHP `max_execution_time`), иначе воркер будет
  убит раньше, чем контекст успеет ответить.
- **`PreflightClient` не может ограничить запрос по времени.** У PSR-18 нет
  per-request опций: он проверяет контекст до отправки и после ответа, а
  сколько вызов блокируется, решает таймаут самого клиента.
- **`ContextClient` переводит в таймаут только остаток бюджета.** Синхронный
  вызов Guzzle вернётся по этому таймауту; `cancel()`, вызванный за это
  время, будет замечен только после возврата. `remainingMs()` на активном
  контексте — минимум 1 мс, потому что для curl нулевой таймаут означает
  отсутствие таймаута.

## Использование

### `ContextMiddleware` (PSR-15)

Создаёт по контексту на запрос, публикует его в атрибутах запроса и отменяет
в `finally`:

```php
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;

$middleware = new ContextMiddleware(
    timeout: 5.0,                              // секунды или fn (ServerRequestInterface): float
    deadlineHeader: 'X-Request-Timeout-Ms',    // необязательно: принимать бюджет от вызывающего
    responseFactory: $psr17Factory,            // необязательно: исключения контекста → 504/499
);

// в обработчике
$context = $request->getAttribute(Context::class);
$controller = $request->getAttribute(ContextController::class);
$correlationId = $request->getAttribute(CorrelationId::class);   // есть всегда
```

| Аргумент | По умолчанию | Эффект |
|---|---|---|
| `timeout` | `30.0` | Бюджет запроса в секундах или callable, вычисляющий его по запросу |
| `correlationHeader` | `X-Correlation-ID` | Входящий заголовок correlation id |
| `contextAttribute` / `controllerAttribute` / `correlationAttribute` | имена классов | Имена атрибутов запроса |
| `timeSource` | `HrTimeSource` | Монотонные часы (для тестов) |
| `deadlineHeader` | `null` | Входящий бюджет в целых миллисекундах (1–999999999); он только **сокращает** таймаут — более длинное или некорректное значение игнорируется |
| `correlationIdGenerator` | `CorrelationId::generate(...)` | Используется, когда заголовка нет или он невалиден |
| `responseFactory` | `null` | Если задан: `ContextExpiredException` → `expiredStatus`, `ContextCancelledException` → `cancelledStatus`; иначе исключения пробрасываются |
| `expiredStatus` / `cancelledStatus` | `504` / `499` | Коды ответа для этих исключений |

У каждого запроса есть `CorrelationId`: входящий заголовок, если он валиден,
иначе свежесгенерированный. Значение кладётся и в контекст
(`$context->value(CorrelationId::class)`), чтобы исходящие клиенты могли
передать его дальше.

### `PreflightClient` (PSR-18)

```php
use Rasuvaeff\Context\Http\PreflightClient;

$client = new PreflightClient(
    $psr18Client,
    $context,
    deadlineHeader: 'X-Request-Timeout-Ms',   // необязательно: передать оставшийся бюджет
    correlationHeader: 'X-Correlation-ID',    // необязательно: передать correlation id
);
$response = $client->sendRequest($request);
```

Проверяет `assertActive()` перед отправкой запроса и после получения ответа.
В PSR-18 нет транспортных опций, поэтому декоратор **не может задать таймаут
блокирующему вызову клиента** — настройте таймаут самого клиента или
используйте `ContextClient` с Guzzle. Явный заголовок correlation id в
запросе важнее значения из контекста.

### `ContextClient` (Guzzle)

```php
use Rasuvaeff\Context\Http\ContextClient;

[$context, $controller] = Context::background()->withTimeout(2.0);
$response = (new ContextClient($guzzle, deadlineHeader: 'X-Request-Timeout-Ms'))->send($request, $context);
$controller->cancel();
```

- Оставшийся бюджет становится `timeout` и `connect_timeout` Guzzle.
- Тело запроса уходит своим потоком; версия протокола сохраняется.
- `http_errors` выключен: ответ 4xx/5xx **возвращается**, как в PSR-18.
- Сбой транспорта после исчерпания бюджета — `ContextExpiredException`, у
  которого `previous` — исключение транспорта; после отмены —
  `ContextCancelledException`; иначе исключение транспорта пробрасывается.
- Конструктор отвергает объект без Guzzle-совместимого метода `request()`.

### Один дедлайн на цепочку сервисов

```
вызывающий ──X-Request-Timeout-Ms: 800──▶ ContextMiddleware(deadlineHeader)
                                           └─ PreflightClient(deadlineHeader) ──X-Request-Timeout-Ms: 790──▶ следующий сервис
```

Используйте одно и то же имя заголовка с обеих сторон. Принимающий middleware
никогда не доверяет бюджету длиннее собственного `timeout`.

### Потребители очередей

Держите контроллер у владельца сообщения и создавайте свежий контекст из TTR
сообщения; вызывайте `assertActive()` перед каждым шагом, а на
`ContextExpiredException` делайте NACK или отправляйте в dead-letter. Пакет не
зависит от реализации очереди.

### Публичный API

| Класс | Описание |
|---|---|
| `ContextMiddleware` | PSR-15: атрибуты контекста, контроллера и correlation id; заголовок бюджета; отображение исключений в ответы |
| `PreflightClient` | Декоратор PSR-18: проверки до и после запроса, исходящие заголовки бюджета и correlation id |
| `ContextClient` | Guzzle-совместимый адаптер: бюджет → таймауты, семантика ошибок PSR-18, отображение истечения |
| `CorrelationId` | Валидированный correlation id (`isValid()`, `generate()`) |

## Безопасность

- **Correlation id валидируется**: 1–128 символов из `A-Z a-z 0-9 . _ : -`.
  Невалидное входящее значение заменяется, а не логируется и не передаётся
  дальше — никакой инъекции в логи и неограниченных значений заголовка.
- **Входящий бюджет — недоверенный вход**: принимаются только целые
  миллисекунды в диапазоне 1–999999999, и он может только сократить
  настроенный таймаут.
- Исключения контекста, отображённые в ответы, не несут деталей; всё остальное
  уходит в ваш обработчик ошибок.
- Все адаптеры кооперативные: не дают работе начаться и ответу быть
  использованным после завершения контекста, но не могут прервать
  произвольный блокирующий вызов PSR-18.

## Примеры

Исполняемые скрипты — в [examples/](examples/).

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | Middleware принимает бюджет и correlation id от вызывающего, `PreflightClient` передаёт оба дальше, отображение ответов | нет |

## Разработка

На хосте нет PHP/Composer — всё через Docker-образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make: `make build`, `make cs-fix`, `make test`, `make test-coverage`,
`make mutation`, `make release-check`.

## Лицензия

[BSD-3-Clause](LICENSE.md)
