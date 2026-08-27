# Отчёт о модульных тестах PHP
**语言 / Languages:** [中文](php-unit-report.md) · [English](php-unit-report.en.md) · [한국어](php-unit-report.ko.md) · [Русский](php-unit-report.ru.md) · [Deutsch](php-unit-report.de.md) · [Français](php-unit-report.fr.md) · [Español](php-unit-report.es.md) · [Português](php-unit-report.pt.md) · [हिन्दी](php-unit-report.hi.md) · [العربية](php-unit-report.ar.md) · [বাংলা](php-unit-report.bn.md) · [Bahasa Indonesia](php-unit-report.id.md) · [日本語](php-unit-report.ja.md)

- Дата: 2026-08-27
- Выполнение: `vendor/bin/phpunit` (PHPUnit 10.5.64, PHP 8.3.7)
- Охват: admin/ (админ-панель webman) + service/ (основной сервис webman)

## Общий итог

| Проект | Тестов | Утверждений | Результат |
|------|------|------|------|
| service | 159 | 408 | ✅ Все пройдены (OK) |
| admin | 67 | 180 | ✅ Все пройдены (OK) |

## Описание окружения

- MySQL 127.0.0.1:3306 (root, пустой пароль), БД `social` (social_*) и `open_admin` (erik_*) созданы и наполнены данными (роль super_admin, 39 прав)
- Redis 127.0.0.1:6379 запущен (хранение капчи `poster:captcha:*`); Elasticsearch не запущен (health check деградирует до unavailable, не считается падением)
- service работает на 8788, admin — на 8791
- Ни у service, ни у admin нет `.env` (репозиторий удалил случайно закоммиченные env, commit e5379fc); приложения работают на фолбэках `getenv('X') ?: значение по умолчанию` в `config/*.php`
- **Расширение Imagick загружено, но отсутствует константа `RESOURCETYPE_PIXELS`** (в этой сборке только новый набор констант RESOURCETYPE_*); конструктор ImagickDriver от poster-php обращается к этой константе и сразу падает

## service (159/159 полностью зелёный)

- Соответствует базовой линии прошлой партии; покрытие: аутентификация/мидлвары/JWT, пользователи, посты, комментарии, подписки, уведомления, синхронизация поиска, IM, комнаты, звонки (CallCenter/CallState), голос, связи моделей, обработка действий (WS)
- M5 добавил модуль стримов (LiveCenter: создание/детали/данмаку/связь микрофонов/закрытие), 23 теста, без регрессий

## admin (прошлая партия 49/60 → эта партия 67/67 полностью зелёный)

### Исправление: реальный дефект кода (1 место)

| Место | Первопричина | Исправление |
|------|------|------|
| `config/poster.php` | `image.driver` по умолчанию `auto`; DriverFactory при обнаружении расширения Imagick выбирает ImagickDriver, но у локального Imagick нет константы `RESOURCETYPE_PIXELS` → генерация капчи/постера сразу 500 (онлайн-сервис затронут так же) | Добавлена защита по константе в детекции драйвера: `getenv('POSTER_IMAGE_DRIVER') ?: (defined('Imagick::RESOURCETYPE_PIXELS') ? 'auto' : 'gd')`; при отсутствии константы автоматический фолбэк на GD |

### Исправление: устаревшие утверждения (обновлены после сверки с текущим кодом)

| Файл теста | Тест | Первопричина | Исправление |
|----------|------|------|------|
| EnvConfigTest | env_file_exists / env_example_file_exists / getenv_reads_env_variables / config_env_keys_exist_in_dotenv (4 падения + 1 ошибка) | Утверждается существование `.env`/`.env.example` и наличие значений getenv; но репозиторий удалил env-файлы, восстановить их нельзя | Переписано как контракт «работа без .env»: каждый ключ `getenv()` обязан иметь фолбэк `?:`, конфигурация по умолчанию указывает на локальные сервисы (127.0.0.1:3306/open_admin), типы ключевых настроек корректны |
| BackendEnhancementTest | test_admin_user_source_contains_searchable | AdminUser больше не использует trait Searchable (вместо него `Erikwang2013\Encryptable\Encryptable` для прозрачного шифрования полей; `toSearchableArray()` сохранён) | Утверждение изменено на trait Encryptable; утверждение toSearchableArray и так проходило, оставлено |
| BackendEnhancementTest | test_middleware_config_contains_cors_and_rate_limit | `config/middleware.php` переведён на формат глобального группового ключа `'@'`; верхнеуровневый массив больше не содержит классы мидлваров напрямую | Утверждение изменено на проверку, что `$middlewares['@']` содержит Cors и RateLimit |
| CaptchaTest | все 7 тестов (изначально 6 ошибок + 1 падение) | Двойное устаревание: (a) отсутствует константа Imagick (уже исправлено в poster.php); (b) утверждения основаны на старом контракте poster-php — `extra.targets` (с x/y) заменён на `extra.texts` (только text+order), координаты живут только в слое хранения; формат клика изменён с `['x'=>, 'y'=>]` на пары чисел `[x, y]` | Переписано по текущему контракту: структура/количество сложностей (2/3/4)/валидация полей; правильный клик читает координаты из Redis (`poster:captcha:{key}` → `data.targets`) и валидирует; неверный клик падает; после max_attempts (3) ключ потребляется/удаляется; уникальность ключа |

### Новые тесты (1 файл, 12 тестов)

`tests/AdminControllerTest.php` (с заголовком copyright), покрытие:

- **BaseController::decodeId** (только что исправленное поведение 404): encode/decode-циклы консистентны; невалидный hashid бросает `support\exception\NotFoundException` с code=404; encodeIds меняет только ID-поля
- **RoleController**: update роли super_admin возвращает 403 (реальные данные БД)
- **PermissionController::buildTree**: вложенность дерева прав (2 уровня) + все id узлов переведены в hashid
- **ConfigController**: отсутствие group/key/value → валидация 422; невалидный hashid → 404
- **ExportController**: экспорт `admin_user` — список чувствительных полей phone/email/id_card (остальные таблицы пусты); PDF-HTML экранирует заголовок/значения ячеек через htmlspecialchars (защита от XSS) и содержит копирайт-объявление

### Известные замечания

- Request webman, конструируемый в тестах, передаётся как сырое HTTP-сообщение (buffer) — параметр конструктора workerman Request — это buffer, передача только method/uri не позволяет распарсить POST-тело; см. комментарии в AdminControllerTest
- Тест правильного клика капчи читает сохранённые цели из Redis; если Redis недоступен, тест помечается markTestSkipped и не влияет на результат набора

## Не покрыто / добавить

- Шифрование/дешифрование Encryptable моделей admin, мидлвары OperationLog/AdminPermission и пути RBAC-кэша по-прежнему без юнит-тестов; рекомендуется покрыть API-тестами или следующей партией
- Пути service, зависящие от внешних сервисов (ES/gRPC), остаются только на юнит-проверке стабами; интеграционный уровень покрывается API-тестами
