# WTG Test API

REST API на Laravel

Репозиторій: https://github.com/alexanderlysak42/wtg_test_laravel

## Стек

- PHP 8.3
- Laravel 12
- MySQL 8
- Redis (черга)
- Docker / Docker Compose

## Основні сутності

| Сутність      | Опис                                                 |
|---------------|------------------------------------------------------|
| `Supplier`    | Постачальник пропозицій (`supplier-a`, `supplier-b`) |
| `Property`    | Об'єкт житла, унікальний за `code`                   |
| `Offer`       | Пропозиція постачальника на конкретні дати           |
| `Import`      | Факт запиту на імпорт та його статус                 |
| `Reservation` | Бронювання конкретної пропозиції                     |

## Встановлення та запуск

Проєкт розгортається через Docker - окремо встановлювати PHP/MySQL/Redis на хості не
потрібно.

```bash
git clone https://github.com/alexanderlysak42/wtg_test_laravel.git
cd wtg_test_laravel

cp .env.example .env
```

У `.env` потрібно заповнити `DB_PASSWORD` та `DB_ROOT_PASSWORD`.

```bash
docker compose up -d --build

docker compose exec app composer install
docker compose exec app php artisan key:generate
```

## Міграції та сидери

```bash
docker compose exec app php artisan migrate --seed
```

Сидер (`SupplierSeeder`) створює двох постачальників: `supplier-a`, `supplier-b`.

Тестова база даних для feature-тестів (`wtg_test_testing`) створюється автоматично при першому
старті контейнера MySQL (init-скрипт `docker/mysql/init/`), вручну нічого робити не потрібно.

## Черга

Обробка імпорту виконується асинхронно всередині `Job` (`ProcessImportJob`), а не в
HTTP-запиті. Воркер вже запущений як окремий сервіс `queue` у `docker-compose.yaml`
(`php artisan queue:work`).

```bash
docker compose logs -f queue
docker compose restart queue
```

## Тести

```bash
docker compose exec app php artisan test
```

## API документація (Swagger)

Після запуску контейнерів документація доступна за адресою:

```
http://localhost:8080/api/documentation
```

Перегенерувати документацію:

```bash
docker compose exec app php artisan l5-swagger:generate
```

## Ендпоінти

| Метод  | Шлях                               | Опис                                                              |
|--------|------------------------------------|-------------------------------------------------------------------|
| `POST` | `/api/imports`                     | Поставити імпорт пропозицій у чергу на обробку                    |
| `GET`  | `/api/imports/{import}`            | Отримати поточний статус асинхронного імпорту                     |
| `GET`  | `/api/properties`                  | Знайти актуальні об'єкти житла з найдешевшою пропозицією на кожен |
| `POST` | `/api/offers/{offer}/reservations` | Забронювати пропозицію                                            |

## Захист від повторного відправлення одного і того ж імпорту

- Пара `(supplier, external_import_id)` унікальна на рівні БД (складений унікальний індекс у
  таблиці `imports`). Повторна відправка того самого імпорту не створює другий запис і не ставить
  повторне завдання в чергу, ендпоінт знаходить існуючий `Import` і повертає його поточний статус.
- Пара `(supplier, offer.external_id)` унікальна в таблиці `offers`. При повторному імпорті
  пропозиція з уже наявним `external_id` не дублюється, а оновлюється (пакетний `upsert()`).
- `Property` ідентифікується за `code` і перевикористовується між імпортами (пакетний `upsert()`).
- Уся обробка пропозицій одного імпорту обгорнута в одну транзакцію, при помилці на будь-якому
  кроці відкочуються всі зміни цього імпорту, а не створюється частково оброблений стан.

## Захист від подвійного бронювання останньої одиниці

При створенні бронювання (`POST /api/offers/{offer}/reservations`) рядок `offers` читається
всередині транзакції через `SELECT ... FOR UPDATE` (`Offer::lockForUpdate()`). MySQL/InnoDB
ставить ексклюзивне блокування на цей рядок до кінця транзакції: якщо два запити одночасно
намагаються забронювати ту саму пропозицію, другий чекає, поки перший не завершить
транзакцію (`commit`/`rollback`), після цього читає вже актуальне значення
`available_units`. Обидва запити ніколи не побачать одне й те саме "застаріле" число місць,
при `available_units = 1` бронювання отримає лише один із двох запитів, другий
отримає `409 Conflict`.
