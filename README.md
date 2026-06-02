# Printer Monitor

Локальная админ-панель на Laravel + Filament для мониторинга сетевых принтеров по SNMP, просмотра состояния тонеров и управления совместимыми наборами картриджей.

## Возможности

- авторизованная админка Filament на `/admin`
- ручной CRUD принтеров
- страница деталей принтера с сетью, SNMP, ошибками и тонерами
- вложенное управление `CartridgeSet` и `Cartridge` прямо в форме принтера
- синхронное сетевое сканирование по CIDR из UI
- команды `printers:scan`, `printers:poll`, `printers:poll-one`
- фоновый polling через Redis queue и scheduler

## Стек

- PHP 8.4
- Laravel 12
- Filament 5
- PostgreSQL
- Redis
- Docker Compose
- SNMP v2c

## Запуск через Docker

1. При необходимости отредактируйте `.env.docker`.
2. Для локального запуска вне Docker можно отдельно использовать `.env` или скопировать `.env.example`.
3. Запустите:

```bash
docker compose up -d --build
```

После старта приложение будет доступно по адресу [http://localhost:8080/admin](http://localhost:8080/admin).

## Контейнеры

- `app` — Laravel PHP-FPM
- `nginx` — web entrypoint
- `postgres` — база данных
- `redis` — cache/session/queue backend
- `worker` — queue worker
- `scheduler` — планировщик `printers:poll`

## Admin user

При старте контейнера автоматически выполняются миграции и сидирование `AdminUserSeeder`.

По умолчанию compose использует `.env.docker`.

Учётные данные:

- email: значение `ADMIN_EMAIL`
- пароль: значение `ADMIN_PASSWORD`

Повторно создать admin-пользователя:

```bash
docker compose exec app php artisan db:seed --class=AdminUserSeeder --force
```

## Artisan-команды

Сканирование сети:

```bash
docker compose exec app php artisan printers:scan 192.168.1.0/24 --community=public
```

Опрос всех активных принтеров:

```bash
docker compose exec app php artisan printers:poll
```

Опрос одного принтера:

```bash
docker compose exec app php artisan printers:poll-one 1
```

## UI

### Printers

- список показывает имя, IP, модель, статус, low toner и последние времена контакта
- доступны действия `View`, `Rename`, `Poll`, `Edit`, `Delete`

### Printer detail

Страница принтера показывает:

- общую информацию
- сетевые данные и SNMP-настройки
- состояние тонеров
- связанные наборы картриджей и картриджи

### Network Scan

Страница `Network Scan` позволяет:

- ввести CIDR, community и timeout
- запустить синхронный поиск SNMP-принтеров
- выбрать найденные устройства
- импортировать их в каталог

## Scheduler и очередь

В `bootstrap/app.php` настроен запуск `printers:poll` каждые 10 минут. Команда ставит задачи в Redis queue, а контейнер `worker` их исполняет.

## SNMP и отладка

Используются OID:

- `1.3.6.1.2.1.1.1.0` — `sysDescr`
- `1.3.6.1.2.1.1.5.0` — `sysName`
- `1.3.6.1.2.1.1.6.0` — `sysLocation`
- `1.3.6.1.2.1.43.5.1.1.16.1` — printer name
- `1.3.6.1.2.1.43.5.1.1.17.1` — serial number
- `1.3.6.1.2.1.43.11.1.1.6.1` — supplies description
- `1.3.6.1.2.1.43.11.1.1.9.1` — supplies level
- `1.3.6.1.2.1.43.11.1.1.8.1` — supplies max capacity

Пример ручной проверки:

```bash
snmpwalk -v2c -c public 192.168.1.100 1.3.6.1.2.1.43.11.1.1
```

## Ограничения v1

- поддерживается только SNMP `v2c`
- история результатов scan не сохраняется
- MAC-адрес автоматически не извлекается
- большие CIDR-диапазоны ограничены `PRINTERS_SCAN_MAX_HOSTS`
