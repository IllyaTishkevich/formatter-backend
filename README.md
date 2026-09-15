# formatter-backend

Symfony-бэкенд для [validformat.online](https://validformat.online). Предоставляет два API-эндпоинта: прокси для выполнения HTTP-запросов из браузера в обход CORS и эндпоинт для получения диагностической информации о запросе (IP, браузер и т.д.).

## Требования

- PHP >= 8.1
- Composer

## Установка

```bash
composer install
cp .env .env.local # при необходимости переопределить настройки локально
```

Запуск локального сервера для разработки:

```bash
symfony server:start
# или
php -S 127.0.0.1:8000 -t public
```

## Документация

- [Переменные окружения](documents/env.md)
- [API](documents/api.md)
- [Консольные команды](documents/console-commands.md)

## Админ-панель

Панель администрирования собрана на [EasyAdminBundle](https://symfony.com/bundles/EasyAdminBundle/current/index.html) и доступна по адресу `/admin`.

### База данных

Используется SQLite, файл хранится в `var/data.db` (создаётся автоматически, в git не коммитится). Применить миграции:

```bash
php bin/console doctrine:migrations:migrate
```

### Вход через Google

Доступ в `/admin` защищён (`ROLE_ADMIN`) и открывается только через вход по Google-аккаунту. Список допущенных админов хранится в таблице `app_user` (сущность `App\Entity\User`) — вход разрешён только тем email, которые туда добавлены; остальным Google-аккаунтам будет отказано в доступе.

1. Создать OAuth 2.0 Client ID в [Google Cloud Console](https://console.cloud.google.com/apis/credentials) (тип — Web application).
2. Указать Authorized redirect URI: `https://ваш-домен/connect/google/check` (для локальной разработки — `http://127.0.0.1:8000/connect/google/check`).
3. Прописать полученные `Client ID` и `Client secret` в `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` (через `.env.local` или переменные окружения на сервере — подробнее в [документации по env](documents/env.md)).
4. Добавить хотя бы одного админа (команда описана в [документации по консольным командам](documents/console-commands.md)):

```bash
php bin/console app:user:add admin@example.com
```

После этого переход на `/admin` без сессии редиректит на `/login`, оттуда — на Google; после успешного входа с зарегистрированным email происходит редирект обратно в дашборд `/admin`.

### CRUD пользователей

В дашборде доступен раздел **Users** — список, создание, редактирование и удаление записей таблицы `app_user` (email, роли, дата создания).

### Block checks

Раздел **Block checks** в дашборде показывает записи таблицы `block_check` (`id`, `date`, `ip`), которые пишет трекинг-пиксель [`GET /api/check/image`](documents/api.md#get-apicheckimage--трекинг-пиксель) при каждом показе картинки на стороннем сайте.
