# API

## Валидация доступа к API

Все `/api/*` эндпоинты используют общий сервис `App\Security\ApiAccessGuard` (`src/Security/ApiAccessGuard.php`), который:

- разрешает запрос, если `Origin` запроса входит в `API_ALLOWED_ORIGINS`, **или** IP клиента входит в `API_ALLOWED_CLIENT_IPS`;
- в остальных случаях возвращает `403 Forbidden`;
- проставляет CORS-заголовки (`Access-Control-Allow-Origin`, `Vary: Origin`, `Access-Control-Allow-Methods`, `Access-Control-Allow-Headers`, `Access-Control-Max-Age`) только когда `Origin` совпал с разрешённым — это не полноценная аутентификация, а защита от случайных/браузерных обращений с чужих сайтов (не-браузерный клиент может подделать `Origin`).

При добавлении нового `/api/*` эндпоинта нужно заинжектировать `ApiAccessGuard` и вызывать `isRequestAllowed()` / `withCors()` так же, как это сделано в существующих контроллерах — не дублировать логику валидации.

Настройка `API_ALLOWED_ORIGINS` / `API_ALLOWED_CLIENT_IPS` — см. [переменные окружения](env.md).

## `POST /api/request` — прокси HTTP-запросов

Позволяет фронтенду выполнять произвольный HTTP-запрос к сторонним URL в обход CORS-ограничений браузера. Поддерживает `OPTIONS` для CORS preflight.

**Тело запроса (JSON):**

```json
{
  "method": "GET",
  "url": "https://example.com/api/something",
  "headers": { "Accept": "application/json" },
  "body": null
}
```

- `method` — один из `GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS` (по умолчанию `GET`).
- `url` — обязателен, должен быть `http`/`https` и указывать на публичный адрес (запросы к `localhost`, приватным/зарезервированным диапазонам IP и адресам, резолвящимся только в них, отклоняются — защита от SSRF).
- `headers` — необязательный объект заголовков; `Host`, `Content-Length`, `Connection` отбрасываются.
- `body` — необязательное тело запроса (не используется для `GET`/`HEAD`); ограничение — 2 МБ.

**Успешный ответ (200):**

```json
{
  "ok": true,
  "status": 200,
  "statusText": "OK",
  "headers": { "content-type": "application/json" },
  "body": "...",
  "time": 123
}
```

**Ограничения и ошибки:**

- ответ целевого сервера ограничен 5 МБ (`502`, если превышен);
- таймаут запроса — 15 секунд;
- редиректы не выполняются автоматически (`max_redirects: 0`);
- при ошибке валидации — `400` с `{"error": "..."}`; при ошибке сети/таймауте — `502`.

## `GET /api/ip` — информация о запросе

Возвращает максимум доступной информации о том, кто сделал запрос: IP, версия браузера, заголовки и т.д. Поддерживает `OPTIONS` для CORS preflight.

**Пример ответа (200):**

```json
{
  "ip": "203.0.113.10",
  "ips": ["203.0.113.10"],
  "forwardedFor": null,
  "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) ... Chrome/128.0.0.0 Safari/537.36",
  "browser": { "name": "Chrome", "version": "128.0.0.0" },
  "clientHints": {
    "brands": "\"Chromium\";v=\"128\", \"Not;A=Brand\";v=\"24\"",
    "platform": "\"Windows\""
  },
  "accept": "*/*",
  "acceptLanguage": "ru-RU,ru;q=0.9",
  "preferredLanguages": ["ru"],
  "acceptEncoding": "gzip, deflate, br",
  "dnt": null,
  "referer": "https://validformat.online/",
  "origin": "https://validformat.online",
  "host": "api.validformat.online",
  "port": 443,
  "scheme": "https",
  "secure": true,
  "protocolVersion": "HTTP/2.0",
  "method": "GET",
  "requestTime": "2026-09-15T23:22:12+03:00",
  "headers": { "host": "...", "accept": "...", "...": "..." }
}
```

Примечания:

- `browser` — результат best-effort разбора `User-Agent` регулярными выражениями; `clientHints` (`Sec-CH-UA-*`) — более надёжный источник имени/версии браузера и платформы, если браузер их присылает (актуально для Chromium-браузеров).
- в `headers` заголовки `Cookie` и `Authorization` намеренно исключены и никогда не возвращаются, даже тому же клиенту, который их отправил.

## `GET /api/check/image` — трекинг-пиксель

Отдаёт минимально возможную картинку (1×1 GIF, 34 байта) для встраивания через `<img src="...">` на стороннем сайте. При каждом запросе создаёт запись в таблице `block_check` (`ip` запроса + текущая дата/время) — по этим записям затем считается статистика (сколько раз блок был показан за сутки, сколько раз пересоздавался и т.д.) через CRUD **Block checks** в [админ-панели](../README.md#админ-панель).

- В отличие от `/api/request` и `/api/ip`, **не** проверяется через `ApiAccessGuard` — эндпоинт намеренно публичный: изображение загружается браузером напрямую (`<img>`, без CORS/preflight и заголовка `Origin`) с произвольных сайтов и IP, которые как раз и нужно собирать.
- Ответ всегда `200 image/gif` с заголовками, запрещающими кеширование (`Cache-Control: no-store, must-revalidate, max-age=0`, `Pragma: no-cache`, `Expires: 0`) — иначе браузер закеширует картинку и повторные показы не долетят до сервера.
- Реализация: `src/Controller/BlockCheckImageController.php`.
