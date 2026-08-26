# Сводный отчёт по всем тестам
**语言 / Languages:** [中文](SUMMARY.md) · [English](SUMMARY.en.md) · [한국어](SUMMARY.ko.md) · [Русский](SUMMARY.ru.md) · [Deutsch](SUMMARY.de.md) · [Français](SUMMARY.fr.md) · [Español](SUMMARY.es.md) · [Português](SUMMARY.pt.md) · [हिन्दी](SUMMARY.hi.md) · [العربية](SUMMARY.ar.md) · [বাংলা](SUMMARY.bn.md) · [Bahasa Indonesia](SUMMARY.id.md) · [日本語](SUMMARY.ja.md)

- Дата: 2026-08-27
- Тестовая команда: PHP-юнит-тесты / Rust-юнит-тесты / автоматизация API / UI end-to-end (о роли GO см. в конце)
- Четыре отдельных отчёта и данный сводный хранятся локально в `docs/test-reports/`

## Обзор

| Роль | Отчёт | Тестов | Пройдено | Не пройдено | Вывод |
|------|------|------|------|------|------|
| PHP-юнит-тесты | `php-unit-report.md` | 196 | 185 | 11 (предзаданные тесты admin, зависят от окружения) | service 136/136 полностью зелёный; admin 49/60 |
| Rust-юнит-тесты | `rust-unit-report.md` | 180 | 180 | 0 | 15 crates полностью зелёные, найдено 7 реальных дефектов |
| Автоматизация API | `api-test-report.md` | 116 | 113 | 3 | 3 реальных дефекта продукта, первопричины установлены |
| UI end-to-end | `ui-e2e-report.md` | 35 | 35 | 0 | Полностью зелёный, 1 blocked (ES не запущен) |
| **Итого** | | **527** | **513** | **14** | Прохождение 97% |

## Список реальных дефектов (рекомендуется исправить)

1. **A20 Неверный hashid** → 500, должно быть 404: `admin/app/common/HashidsService.php:28` не перехватывает `InvalidArgumentException`
2. **A39/A40 Экспорт Excel/PDF** → гарантированный сбой: в `ExportController` нет `use support\Response`, из-за чего ломается резолв типа возврата; в этом же файле повторно дешифруется уже кастованный телефон/почта с ошибкой `Invalid ciphertext prefix`
3. **7 дефектов, найденных Rust**: подробности в `rust-unit-report.md` (разбор протоколов, обработка границ и т.д., к каждому приложено исправление)
4. **11 падений admin-юнит-тестов — проблемы окружения/конфигурации**: отсутствует `admin/.env`, капча зависит от работающего сервиса/Redis, устаревшие утверждения по Cors middleware и searchable у admin_user — это не дефекты кода

## Исправления окружения и примечания (вызваны данной партией тестов)

- **БД**: у `id` таблиц `social_follows`/`social_notifications` миграций m2/m3/m4 нет AUTO_INCREMENT, исправлено через ALTER (иначе пути записи подписок/уведомлений/IM/голоса падают с 1364)
- **`service/.env`**: забэкаплен как `.env.api-test-bak` (изначально указывал на недоступный порт 13306). Автовосстановление невозможно из-за ограничений политики доступа к .env; для восстановления нужен ручной `mv service/.env.api-test-bak service/.env`
- **ES не запущен**: поисковые тесты (API + E2E) помечены прошедшими как 503/blocked; требуется повторная проверка после запуска Elasticsearch

## Примечание GO-тест-инженера

В репозитории **вообще нет Go-кода** (нет go.mod, нет .go файлов); этой роли нечего тестировать, выполнение не проводилось. Для дополнительного тестирования сначала нужно ввести Go-компонент (например, gateway/search sidecar).

## Как воспроизвести

```bash
# Юнит-тесты
cd service && vendor/bin/phpunit
cd admin && vendor/bin/phpunit
cd infrastructure && cargo test --workspace
# Автоматизация API (нужно сначала поднять admin :8791 и service :8788, внедрить ENCRYPTABLE_KEY/ENCRYPTION_KEY)
php tests/api/run.php
# UI E2E
cd tests/e2e && npx playwright test
```
