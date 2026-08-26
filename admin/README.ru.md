# Открытая админ-панель (open-admin)
**语言 / Languages:** [中文](README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Полнофункциональная админ-панель на базе webman v2 + Flutter.

> [English version](README_EN.md) | [Диаграммы архитектуры](docs/ARCHITECTURE.md) | [Дизайн-документ](docs/DESIGN.md) | [Безопасность](docs/SECURITY.md) | [Справочник API](docs/API.md)

## Возможности

| Область | Функция | Описание |
|--------|------|------|
| 🔐 Аутентификация | Вход / обновление токена / выход | Клик-капча + JWT + чёрный список |
| | Блокировка аккаунта | 5 неудачных попыток — блокировка 15 минут |
| | Ограничение сессий | Не более 3 действующих токенов на пользователя |
| 📊 Дашборд | Реальная статистика / тренды / распределение / последние действия | Кэш Redis 5 минут |
| 👥 Пользователи | CRUD + массовое удаление / вкл-выкл | Мягкое удаление + подтверждение паролем |
| | Массовый импорт Excel | Построчная проверка + отчёт об ошибках |
| 🔒 Роли и права | CRUD ролей + дерево прав | Авторизация RBAC с гранулярностью method.path |
| ⚙ Системные настройки | CRUD пар ключ-значение | Групповое управление |
| 📋 Аудит действий | Запросы логов + определение источника | Автоопределение 8 платформ |
| 📁 Файлы | Загрузка / экспорт Excel / экспорт PDF | Автоматическое маскирование данных |
| 🛡 Безопасность | 18 уровней эшелонированной защиты | XSS/SQL-инъекции/пути/команды/CSRF/лимиты/CSP... |
| 🏥 Эксплуатация | Health check / metrics / документация API / security.txt | Prometheus + OpenAPI 3.0 + интерактивная документация hg/apidoc |
| 🌐 Локализация | Переключение китайский/английский | Заголовок Accept-Language / параметр ?lang= |

## Технологический стек

| Слой | Технология | Описание |
|---|------|------|
| Фреймворк | webman v2 (workerman) | Сверхпроизводительный PHP-фреймворк с резидентными процессами |
| Версия PHP | 8.3+ | |
| База данных | MySQL 8.0+ | Префикс таблиц `erik_`, BIGINT-ключи без автоинкремента |
| Поиск | Elasticsearch | Синхронизация и поиск через `webman-scout` |
| Фронтенд админки | Flutter 3.x | Web в стиле десктопной админ-панели (`apps/flutter/`) |
| Мобильный | HarmonyOS ArkTS | Нативный клиент HarmonyOS (`apps/harmonyos/`), телефон/планшет/2in1 |

## Ключевые пакеты

| Пакет | Назначение |
|---|------|
| `erikwang2013/snowflake-php` | Глобально уникальные BIGINT-ключи по алгоритму Snowflake |
| `erikwang2013/hashids` | Шифрование ID на уровне API, скрывает реальные ID БД |
| `erikwang2013/jwt-webman` | Выпуск и проверка JWT-токенов |
| `erikwang2013/encryption` | Шифрование чувствительных данных на транспортном уровне |
| `erikwang2013/encryptable` | Автошифрование чувствительных полей БД |
| `erikwang2013/webman-scout` | Синхронизация и полнотекстовый поиск Elasticsearch |
| `erikwang2013/season` | Данные флагов стран |
| `erikwang2013/poster-php` | Генерация/проверка клик-капчи + генерация постеров |
| `phpoffice/phpspreadsheet` | Экспорт Excel |
| `barryvdh/laravel-dompdf` | Экспорт PDF (на базе Dompdf) |

## Структура проекта

```
open-admin/
├── app/
│   ├── admin/controller/       # 管理端控制器
│   │   ├── DashboardController.php # 仪表盘（Redis缓存）
│   │   ├── UserController.php      # 用户 CRUD + 批量操作
│   │   ├── RoleController.php      # 角色 CRUD
│   │   ├── PermissionController.php# 权限 CRUD
│   │   ├── ConfigController.php    # 系统配置 CRUD
│   │   ├── LogController.php       # 操作日志查询
│   │   ├── ProfileController.php   # 个人中心 + 登出
│   │   ├── ExportController.php    # Excel/PDF 导出
│   │   ├── ImportController.php    # Excel 导入用户
│   │   ├── UploadController.php    # 文件上传
│   │   ├── HealthController.php    # 健康检查
│   │   ├── DocsController.php      # OpenAPI 文档
│   │   └── BaseController.php      # 基础控制器
│   ├── api/
│   │   └── v1/controller/          # API v1 控制器（版本由请求头 API-Version 控制）
│   │       ├── CaptchaController.php # 点击验证码
│   │       └── AuthController.php    # 登录/刷新令牌
│   ├── common/                 # 公共工具类
│   │   ├── HashidsService.php  # ID 编解码
│   │   ├── SnowflakeService.php# Snowflake ID 生成
│   │   └── EncryptionService.php # 数据加解密 + 脱敏
│   ├── middleware/             # 中间件
│   │   ├── Cors.php            # 跨域
│   │   ├── SecurityFilter.php  # 攻击检测拦截（HTTP方法限制/XSS/SQL注入/路径遍历/命令注入/CSRF）
│   │   ├── RateLimit.php       # Redis 限流（滑动窗口 + 响应头）
│   │   ├── ApiVersion.php      # API 版本校验
│   │   ├── AdminAuth.php       # JWT 认证 + 黑名单
│   │   ├── AdminPermission.php # RBAC 权限校验
│   │   └── OperationLog.php    # 操作日志自动记录（含来源端检测）
│   └── model/                  # 数据模型
├── apps/
│   ├── flutter/                # Flutter Web 管理后台（PC 风格）
│   │   └── lib/app/
│   │       ├── pages/          # 5 个完整页面（仪表盘/用户/角色/配置/日志/个人中心）
│   │       ├── services/       # ApiService（JWT 拦截器）+ AuthService（Token 持久化）
│   │       └── layouts/        # 响应式管理后台布局（侧边栏+顶栏+内容区）
│   └── harmonyos/              # HarmonyOS 原生客户端（Token 无感刷新）
├── config/                     # 配置文件（含中文注释）
│   ├── route.php               # 路由 + API 版本策略
│   ├── middleware.php           # 全局中间件注册
│   └── ...                     # 各组件配置
├── database/migrations/        # SQL 迁移文件（含权限种子数据）
├── public/                     # 公共入口
├── runtime/                    # 运行时文件
└── vendor/                     # Composer 依赖
```

## Требования к окружению

- PHP >= 8.3
- Composer 2.x
- MySQL >= 8.0
- Flutter >= 3.41 (только для разработки фронтенда)
- Elasticsearch >= 7.x (опционально, нужно для поиска)

## Быстрый старт

### 1. Установка зависимостей

```bash
composer install
```

### 2. Настройка переменных окружения

Скопируйте и измените переменные окружения (опционально; если не заданы, используются значения по умолчанию из `config/*.php`):

```bash
cp .env.example .env
```

Ключевые параметры:

| Переменная | Описание | По умолчанию |
|---------|------|--------|
| `JWT_SECRET` | Секрет подписи JWT | `open-admin-jwt-secret-change-in-production` |
| `HASHIDS_SALT` | Соль Hashids | `open-admin-hashids-salt-2026` |
| `ENCRYPTION_KEY` | Ключ шифрования API | 32-байтовое значение по умолчанию |
| `SNOWFLAKE_DATACENTER_ID` | ID дата-центра (0-31) | `1` |
| `SNOWFLAKE_WORKER_ID` | ID рабочего узла (0-31) | `1` |
| `SCOUT_HOSTS` | Адрес ES | `http://localhost:9200` |

**В продакшене обязательно замените все ключи на случайные строки.**

### 3. Установка в один клик

Запустите сервис, затем в браузере откройте мастер установки для инициализации БД и создания администратора:

```bash
php start.php start
```

По умолчанию слушает `http://0.0.0.0:8787` (порт меняется в `config/server.php`).

Откройте в браузере **`http://localhost:8787/install`** и заполните по шагам:

| Шаг | Содержимое |
|------|------|
| ① Настройка БД | Хост, порт, имя БД, пользователь, пароль |
| ② Администратор | Имя и пароль администратора (по умолчанию admin / admin888) |

После нажатия «Начать установку» автоматически создаются таблицы, сидится дерево прав, создаётся аккаунт администратора и конфигурация БД записывается в `.env`.

> После установки создаётся файл-блокировка `runtime/install.lock`. Для переустановки просто удалите его.

### 4. Вход

Перейдите на `http://localhost:8787` и войдите с учётными данными, заданными при установке.

### 5. Запуск фронтенда (опционально)

**Flutter-админка (Web):**

```bash
cd apps/flutter
flutter pub get
flutter run -d chrome    # Web 端（PC 管理后台风格）
```

**Клиент HarmonyOS (мобильный):**

Откройте каталог `apps/harmonyos/` в DevEco Studio и запустите на устройстве или эмуляторе.

### 6. Развёртывание Docker Compose в один клик (рекомендуется для продакшена)

Проект предоставляет полную Docker-оркестрацию из 5 сервисов: Nginx, PHP (webman app), MySQL, Redis, Elasticsearch.

```bash
# 1. 配置 Docker 环境变量
cp .env.docker .env

# 2. 启动所有服务
docker-compose up -d

# 3. 浏览器访问安装向导完成初始化
# http://localhost:8787/install  (填入数据库和管理员信息)
# 或手动执行 SQL 迁移（进入 app 容器）:
# docker-compose exec app mysql -h mysql -u root -p < database/migrations/open_admin.sql

# 4. 访问
# http://localhost:8787  (webman)
# http://localhost:8080  (Nginx 反向代理)
```

- `Dockerfile`: PHP 8.3 + OPcache + Composer, на базе `php:8.3-cli`
- `docker-compose.yml`: оркестрация 5 сервисов, изоляция сети, персистентные тома
- `.env.docker`: переменные окружения для Docker


## Соглашения по БД

- **Префикс таблиц**: `erik_`
- **Первичный ключ**: во всех таблицах `id BIGINT UNSIGNED NOT NULL`, **AUTO_INCREMENT запрещён**
- **Генерация ID**: первичные ключи генерируются на уровне приложения через `SnowflakeService::generate()`, уникальны в распределённой среде
- **Обязательные поля**: каждая таблица должна содержать `id`, `created_at`, `updated_at`
- **Мягкое удаление**: где нужно, добавляется `deleted_at DATETIME DEFAULT NULL`
- **Чувствительные поля**: телефон, email, паспорт и т.п. автоматически шифруются плагином `encryptable`, в БД хранится шифротекст в `VARCHAR(500)`

## Справочник API

Полная спецификация API (единый формат ответа, бизнес-коды ошибок, обработка ID, версии API, лимиты, архитектура middleware, потоки аутентификации и капчи) и полный список эндпоинтов — в **[справочнике API](docs/API.md)**.

## Фронтенд

### Flutter-админка (десктопный стиль)

- **Макет**: сворачиваемый сайдбар (64px/240px) + верхняя панель + контент, три адаптивных брейкпоинта (телефон/планшет/десктоп)
- **Страницы**: вход, дашборд, пользователи, роли и права, системные настройки, журнал операций, личный кабинет
- **Состояние**: GetX (синглтон `ApiService` + персистентность токена в `AuthService`)
- **Дашборд**: карточки статистики, линейный график трендов (fl_chart), круговая диаграмма, последние операции
- **Экспорт**: Excel/PDF; в PDF встроена неудаляемая информация об авторских правах
- **Массовые операции**: массовое удаление, массовое включение/отключение
- **Тема**: Material 3, светлая/тёмная

### Мобильный клиент HarmonyOS

- **Страницы**: вход, дашборд, список/детали пользователя, личный кабинет
- **Аутентификация**: JWT Bearer + автоматическое фоновое обновление токена при 401, при неудаче — редирект на страницу входа
- **Хранение**: токен управляется через AppStorage

## Правила разработки

- Глобальные функции/классы не имеют ведущего `\`, импортируются через `use`
- Все PHP-файлы должны содержать копирайт в начале
- Все конфиги должны содержать комментарии на китайском
- Первичные ключи БД генерируются snowflake на уровне приложения, автоинкремент запрещён
- Все ID в параметрах и ответах API шифруются/дешифруются через hashids
- Middleware AdminPermission кэширует права пользователя в Redis (TTL=60s), устраняя проблему N+1 запросов

## Развёртывание

### Docker Compose (рекомендуется)

В корне проекта лежит `docker-compose.yml`, оркестрирующий 5 сервисов:

| Сервис | Образ | Порт |
|------|------|------|
| `nginx` | nginx:alpine | 80, 443 |
| `app` | Сборка из локального `Dockerfile` | 8787 |
| `mysql` | mysql:8.0 | 3306 |
| `redis` | redis:7-alpine | 6379 |
| `elasticsearch` | elasticsearch:8.x | 9200 |

PHP-образ собирается через `Dockerfile` на базе `php:8.3-cli` с включённым OPcache.

```bash
cp .env.docker .env
docker-compose up -d
```

### CI/CD

Конвейер CI на GitHub Actions: `.github/workflows/ci.yml`

- Проверка синтаксиса PHP (`php -l`)
- Модульные тесты PHPUnit
- Статический анализ Flutter (`flutter analyze`)

### Резервное копирование БД

Каталог `database/backup/`:

- `backup.sh` — бэкап mysqldump + gzip, автоочистка старых копий (30 дней)
- `restore.sh` — интерактивное восстановление, показывает доступные бэкапы

### Безопасная конфигурация Nginx

Для продакшена используйте `docs/nginx-security.conf` для усиления безопасности обратного прокси.

## Открытый код — трудный путь, спасибо за поддержку

| WeChat | Alipay |
|:---:|:---:|
| ![WeChat](./docs/weixinpay.png "WeChat") | ![Alipay](./docs/alipay.png "Alipay") |

---

## License

MIT

Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
