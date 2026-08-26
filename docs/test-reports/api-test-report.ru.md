# Отчёт об автоматизированном тестировании API
**语言 / Languages:** [中文](api-test-report.md) · [English](api-test-report.en.md) · [한국어](api-test-report.ko.md) · [Русский](api-test-report.ru.md) · [Deutsch](api-test-report.de.md) · [Français](api-test-report.fr.md) · [Español](api-test-report.es.md) · [Português](api-test-report.pt.md) · [हिन्दी](api-test-report.hi.md) · [العربية](api-test-report.ar.md) · [বাংলা](api-test-report.bn.md) · [Bahasa Indonesia](api-test-report.id.md) · [日本語](api-test-report.ja.md)

- Дата: 2026-08-27
- Выполнение: `tests/api/run.php` (скрипт curl-проверок), результат `tests/api/results.json`
- Охват: admin HTTP API (A01-A45) + service HTTP API (S01-S57b, включая S58-S68)
- Сервисы: admin `http://127.0.0.1:8791`, service `http://127.0.0.1:8788` (WebSocket `:8789` не покрыт в этом раунде HTTP-тестов)

## Вывод

**116 тестов: 113 пройдено / 3 не пройдено (97.4% прохождения); все 3 падения — дефекты продукта с установленной первопричиной**

| Группа | Пройдено/Всего |
|------|-----------|
| admin A01-A45 (аутентификация, капча, управление пользователями, HashID, роли и права, конфигурация, логи, экспорт/импорт, загрузка, health-проверки и т.д.) | 42/45 |
| service S01-S68 (регистрация/вход/выход/обновление, профиль, подписки, посты/лайки/лента, комментарии, уведомления, поиск, IM-сессии/сообщения/пуши, голосовая загрузка/файлы/звонки/комнаты и т.д.) | 71/71 |

## Не пройденные тесты (3, все — дефекты продукта)

| Тест | Ожидание | Факт | Первопричина |
|------|------|------|------|
| A20 Неверный hashid в деталях пользователя | 404 | 500 | `HashidsService::decode()` бросает неперехваченное `InvalidArgumentException` для неверного ID (admin/app/common/HashidsService.php:28, BaseController.php:52); исключение уходит как 500, нужно перехватывать и возвращать 404 |
| A39 Экспорт Excel | поток xlsx | 200+JSON с ошибкой (бизнес-сбой) | `ExportController::excel()` объявляет тип возврата `: Response`, но нет `use support\Response`, тип резолвится в `app\admin\controller\Response` → любой успешный возврат бросает `TypeError` (ExportController.php:122), экспорт полностью неработоспособен |
| A40 Экспорт PDF | поток pdf | 200+JSON с ошибкой (бизнес-сбой) | То же: `ExportController::pdf()` (ExportController.php:135) без `use support\Response` |

> Дополнение (потенциальный дефект в том же файле, сейчас скрыт TypeError выше): `ExportController` строка 90 вызывает `EncryptionService::decrypt()` для phone/email, тогда как у модели `AdminUser` поля `email/phone/id_card` объявлены с кастом `Encryptable::class` (автошифрование при записи, авто-дешифрование при чтении), и экспорт повторно дешифрует открытый текст → как только появится аккаунт с непустым телефоном/почтой, будет бросаться `EncryptionException: Invalid ciphertext prefix for AES-256-CBC`. Проблема воспроизведётся и после исправления типов возврата.

## Проблемы окружения, исправленные во время тестирования (не изменения кода продукта)

1. **В таблицах миграций m2/m3/m4 у `id` нет AUTO_INCREMENT (блокирующая, исправлено)**: `social_follows`, `social_notifications`, созданные `service/database/m2.sql`/`m3.sql`/`m4.sql`, имеют `id BIGINT UNSIGNED NOT NULL` без `AUTO_INCREMENT`; любой INSERT падает с `1364 Field 'id' doesn't have a default value`, блокируя все пути записи подписок/уведомлений/IM/голоса. На машине выполнено `ALTER TABLE ... MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` (остальные 8 таблиц и так с автоприращением). **Сами скрипты миграций стоит дополнить автоприращением.**
2. **service/.env указывает на недоступную БД (блокирующая)**: `DB_PORT=13306` без пароля, а реальный MySQL находится на `127.0.0.1:3306 (root/root)`; `createUnsafeMutable` в webman перекрывает переменные окружения CLI. Во время тестов `.env` перемещён в `service/.env.api-test-bak` (содержимое сохранено как есть), сервис запускался с инъекцией переменных окружения; восстановление не выполнено из-за ограничений политики доступа к .env, требуется вручную `mv service/.env.api-test-bak service/.env` (внимание: после восстановления перезапуск сервиса снова упрётся в недоступную БД).
3. **У admin нет .env, зависит от переменных окружения**: нужны `DB_PASSWORD=root ENCRYPTABLE_KEY(16B) ENCRYPTION_KEY(32B)`. Плагин `encryptable` при незарегистрированном провайдере в контейнере webman откатывается к `EnvEncryptableConfig` (читает `ENCRYPTION_KEY`, cipher по умолчанию aes-256-gcm); несоответствие длины ключа даёт `MissingEncryptionKeyException` при создании аккаунта/импорте/экспорте.
4. **Elasticsearch не запущен**: `GET /api/v1/search/posts` возвращает 503 (предусмотренная деградация); поисковые тесты группы S обработаны как ожидалось (принимается 0 или 503), не засчитаны как падения.

## Несоответствия контракту/документации (рекомендуется исправить, не блокирует)

- Документация капчи (apidoc и комментарии CaptchaController) описывает `clicks=[{x,y}]` как массив объектов, а реализация `poster-php` требует массив пар координат `[[x,y]]`; передача объектов по документации всегда приводит к сбою.
- Голосовая загрузка возвращает `voice_url` как `/voice/{md5}.m4a` (относительно корня API, без префикса `/api/v1`); клиенту нужно самому добавлять `/api/v1` для доступа; доступ к файлам идёт через аутентифицированные маршруты (требуется token).

## Окружение и воспроизведение

- Тестовые учётные данные: тестовый аккаунт `e2e_smoke` (admin, пароль только для тестов) + `apitest_*@test.dev` (service, автоочистка после прогона), все записаны в константы `tests/api/run.php`; реальные ключи не использовались.
- Воспроизведение:

```bash
cd /home/wwwroot/social/admin && DB_PASSWORD=root ENCRYPTABLE_KEY='apitest-enc-16b!!' \
  ENCRYPTION_KEY='apitest-db-encrypt-key-32-byte!!' php start.php start   # admin :8791
cd /home/wwwroot/social/service && DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root \
  DB_PASSWORD=root php start.php start                                     # service :8788
php /home/wwwroot/social/tests/api/run.php                                  # повторный прогон (116 тестов)
```

## Перечень эндпоинтов (по route.php / apidoc)

- service `config/route.php`: 39 HTTP-маршрутов (аутентификация 5, пользователи 2, подписки 5, посты 7, комментарии 2, уведомления 4, поиск 2, IM 4, голос/звонки/комнаты 5, health/документация 3)
- admin `config/route.php`: 33 HTTP-маршрута (аутентификация/капча 4, CRUD пользователей 5, роли 5, права 2, конфигурация 4, логи 1, личный кабинет 4, экспорт 2, импорт 1, загрузка 1, health/документация 4)
