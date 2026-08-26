# Отчёт о модульных тестах PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Дата: 2026-08-27
- Выполнение: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Охват: admin/ (админ-панель webman) + service/ (основной сервис webman)

## Общий итог

| Проект | Тестов | Утверждений | Результат |
|------|------|------|------|
| service | 136 | 348 | ✅ Все пройдены (OK) |
| admin | 60 | 136 | ⚠️ 49 пройдено / 4 ошибки / 7 не пройдено |

## service (полностью зелёный)

- Новые файлы тестов (данная партия): AuthMiddlewareTest, UserBriefTest, SearchSyncTest, ActionHandlerTest, JwtHelperTest, VoiceControllerTest, MonitorTest, ModelRelationTest и др.; после слияния с существующими 24 файлами тестов всего 136 тестов — все пройдены
- Покрытые модули: аутентификация/мидлвары/JWT, пользователи, посты, комментарии, подписки, уведомления, синхронизация поиска, IM, комнаты, звонки (CallCenter/CallState), голос, связи моделей, обработка действий (WS)

### Исправление: случайное зависание тестового набора (важно)

- Симптом: при полном прогоне процесс случайно зависает; прогон одного файла/подмножества проходит
- Первопричина: `new Worker()` в `ActionHandlerTest::setUp` регистрирует экземпляр в **статический реестр** `Worker::$workers`; после этого любой `CallCenter::start` видит «есть Worker» и вызывает `Timer::add` → `pcntl_alarm(1)` устанавливает таймер SIGALRM, процесс зависает при выходе
- Исправление: setUp делает снапшот реестра, tearDown восстанавливает (`ReflectionProperty` возвращает `workers`/`pidMap`)
- Расположение: `service/tests/ActionHandlerTest.php`

## admin (49/60; падения — только предзаданные тесты, это проблемы окружения/конфигурации)

| Тест | Причина падения | Категория |
|------|----------|------|
| EnvConfigTest (4 падения + 1 ошибка) | `admin/.env` не существует, утверждения getenv/dotenv не работают | В тестовом окружении нет .env |
| CaptchaTest (3 ошибки + 1 падение + 1 risky) | Капча зависит от работающего сервиса/Redis, в окружении юнит-тестов возвращается null | Зависимость от окружения |
| BackendEnhancementTest (2 падения) | Утверждается наличие `app/middleware/Cors` и searchable у admin_user — текущая конфигурация не соответствует утверждениям | Устаревшие конфигурационные утверждения |

Примечание: admin/tests — все исторически предзаданные файлы, в этой партии admin-юнит-тесты не добавлялись (фокус был на service).

## Не покрыто / добавить

- Модулям admin (model/middleware/view) не хватает юнит-тестов
- Пути service, зависящие от внешних сервисов (ES/gRPC), прошли только юнит-проверку на стабах; интеграционное покрытие рекомендуется обеспечить API-тестами
