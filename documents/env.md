# Переменные окружения

| Переменная | По умолчанию | Описание |
|---|---|---|
| `APP_ENV` | `dev` | Окружение Symfony (`dev`, `prod`, `test`). |
| `APP_SECRET` | — | Секрет приложения (используется Symfony для CSRF, подписи сессий и т.д.). |
| `API_ALLOWED_ORIGINS` | `https://validformat.online` | Список через запятую `Origin`-адресов, которым разрешено обращаться к любому `/api/*` эндпоинту из браузера. |
| `API_ALLOWED_CLIENT_IPS` | `127.0.0.1,::1` | Список через запятую IP-адресов клиента, которым разрешён доступ ко всем `/api/*` эндпоинтам независимо от `Origin` (для локальной разработки/тестирования на самом сервере). |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data.db` | Строка подключения Doctrine. По умолчанию — файл SQLite в `var/data.db` (не коммитится). |
| `GOOGLE_OAUTH_CLIENT_ID` | — | Client ID OAuth 2.0-приложения Google. Нужен для входа в админку через Google (см. [README → Вход через Google](../README.md#вход-через-google)). |
| `GOOGLE_OAUTH_CLIENT_SECRET` | — | Client Secret того же приложения. |

Переопределять эти значения для конкретного окружения (в т.ч. в проде) следует через `.env.local` / `.env.prod.local`, не редактируя закоммиченный `.env`.

> ⚠️ **`.env` коммитится в git**, `.env.local` и `.env.*.local` — нет (см. `.gitignore`). Реальные секреты (`APP_SECRET` для прода, `GOOGLE_OAUTH_CLIENT_SECRET` и т.п.) всегда кладите только в `.env.local` / `.env.prod.local` или в переменные окружения сервера — никогда в закоммиченный `.env`.

Без `GOOGLE_OAUTH_CLIENT_ID`/`GOOGLE_OAUTH_CLIENT_SECRET` страница `/login` всё равно откроется, но кнопка «Sign in with Google» приведёт к ошибке `invalid_client` от Google.
