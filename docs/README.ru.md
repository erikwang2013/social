# Социальная платформа

**语言 / Languages:** [中文](../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)

Монорепозиторий многоязычной социальной платформы: текстово-графическое сообщество + мгновенные сообщения + стримы/голос + виртуальная экономика.

## О проекте

- **Три нативных клиента**: Android (Kotlin + Compose), iOS (SwiftUI), HarmonyOS (ArkTS), плюс админ-панель на Flutter
- **Бизнес-сервис**: webman v2 (PHP 8.3) обслуживает оба канала — REST и WebSocket; машины состояний стримов/голосовых комнат/звонков 1v1 переведены на Rust (infrastructure/bee-rust); PHP-контроллеры подключаются напрямую по gRPC; API версионируется через `X-Api-Version` (по умолчанию v1, совместимо со старыми путями `/api/vX`)
- **Собственный медиаслой**: mediasoup SFU + coturn TURN для пересылки медиа в голосовых звонках 1v1 и голосовых комнатах (8 мест)
- **Многоуровневое состояние**: MySQL — источник бизнес-данных, Redis — реальное время для состояния сессий / IM / звонков / комнат
- **Вехи**: M0–M5 сданы (голосовые сообщения, звонки 1v1, голосовые комнаты, стриминг); M6a — виртуальная экономика: кошелёк (баланс/журнал, MySQL единственный источник правды), подарки с долей стримера, мобильное пополнение IAP (App Store / Google Play / Huawei); M6b — платёжные каналы: скелет зачисления пополнения (проверка подписи колбэка WeChat/Alipay/Stripe, серверные цены, идемпотентное зачисление; вывод и сверка сданы); M6c — CDN-хранилище: провайдеры настраиваются из админ-панели (S3-совместимо: AWS S3 / Cloudflare R2 / Aliyun OSS / Tencent COS / Backblaze B2); изображения/голос/файлы раздаются через объектное хранилище + CDN; M6d — отчёты админки и статистика дашборда: модуль отчётов (пользователи/платежи/выводы — фильтр по датам, итоги, тренды, распределения, экспорт в Excel) и карточки статистики платформы на главной странице

## Обзор функций

![Обзор функций](diagrams/features.ru.svg)

## Архитектура

![Архитектура](diagrams/architecture.ru.svg)

## Основные бизнес-процессы

![Основные бизнес-процессы](diagrams/core-flow.ru.svg)

## Жизненный цикл

![Жизненный цикл](diagrams/lifecycle.ru.svg)

## Дизайн модулей

![Дизайн модулей](diagrams/module-design.ru.svg)

## Структура проекта

| Каталог | Описание | Технологии |
|------|------|------|
| contracts/ | gRPC-контракты (proto, точка входа генерации buf) | protobuf / buf |
| service/ | Бизнес-сервис для пользователей (REST :8788 + WS :8789) | webman v2 (PHP 8.3) |
| admin/ | Админ-панель (на базе open-admin) | webman v2 + Flutter |
| infrastructure/ | Вычислительный слой высокой пропускной способности (live/voice gRPC-сервисы) | bee-rust (tonic) |
| media/sfu/ | Собственный медиаслой (mediasoup SFU :8790 + coturn :3478) | Node.js (включён с M4) |
| apps/ | Три нативных клиента | SwiftUI / Kotlin+Compose / ArkTS |

Внутренняя структура service:

```
service/
├── app/
│   ├── controller/   # REST-контроллеры (auth/post/follow/im/voice/wallet/gift/...)
│   ├── common/        # WalletService (баланс/журнал/идемпотентно) · GiftService (подарки/доля)
│   ├── ws/           # WsServer · протокол кадров Envelope · доставка через Deliverer · ConnectionRegistry
│   ├── call/         # CallCenter: конечный автомат звонка 1v1 (перенесено на Rust в M6; PHP-сторона оставлена для WS-сигнализации)
│   ├── room/         # RoomCenter: голосовые комнаты (перенесено на Rust в M6; PHP-сторона оставлена для WS-сигнализации)
│   ├── live/         # LiveCenter: стрим-комнаты (перенесено на Rust в M6; PHP-сторона оставлена для WS-сигнализации)
│   ├── model/        # Модели данных
│   ├── process/      # Пользовательские процессы Http / WsServer
│   └── storage/      # Хранение голосовых файлов (m4a; с M6 обеспечивается Rust VoiceStorage)
├── config/           # route.php (группа маршрутов /api/v1) · process.php (:8788/:8789)
└── tests/            # phpunit-тесты + чёрный ящик E2E: im_e2e.php / voice_e2e.php / live_e2e.php / wallet_e2e.php
```

## Установка одной командой

Предварительные требования: PHP ≥ 8.3 (composer), MySQL, Redis (Docker опционально, для медиаслоя).

```bash
./install.sh
```

Скрипт: выполняет `composer install` по разу для `service/` и `admin/`; создаёт базу данных из `database/install.sql` (идемпотентно, CREATE IF NOT EXISTS); генерирует `.env` для обоих сервисов (случайные ключи JWT / APP, существующие файлы не перезаписываются); опционально запускает медиаслой (`docker compose up -d` для media/sfu и coturn, `--skip-media` — пропустить); в конце выводит команды запуска каждого сервиса и адреса доступа.

## Установка вручную

1. Установить зависимости:

```bash
cd service && composer install
cd admin && composer install
```

2. Создать базу данных:

```bash
mysql -u root -p < database/install.sql
```

3. Настроить окружение: скопировать `service/.env.example` и `admin/.env.example` в `.env`, заполнить ключи DB / Redis / JWT / APP (в продакшене всегда используйте случайные ключи).

4. Запустить сервисы:

```bash
cd service && php start.php start -d   # HTTP :8788 · WS :8789
cd admin && php start.php start -d     # admin :8787
```

5. Запустить медиаслой (опционально):

```bash
cd media/sfu && docker compose up -d --build   # SFU :8790 · coturn :3478
```

## Использование

### Зависимости

- PHP ≥ 8.3 (composer)
- Redis (по умолчанию 127.0.0.1:6379)
- Node.js ≥ 18 (локальная отладка SFU)
- Docker (контейнеры SFU / coturn)

### Запуск бизнес-сервиса

```bash
cd service
composer install
php start.php start -d      # HTTP :8788 · WS :8789
```

При необходимости настройте `REDIS`, `SFU_URL` (по умолчанию 127.0.0.1:8790) в `service/.env`.

### Запуск медиаслоя

```bash
cd media/sfu
docker compose up -d --build   # SFU :8790 (RTC UDP 10000-10200) · coturn :3478
```

### Клиенты

| Платформа | Как открыть / собрать | Требования |
|----|----------------|----------|
| Android | `cd apps/android && ./gradlew assembleDebug` | Сборка на Linux / macOS |
| iOS | Открыть `apps/ios/SocialApp` в Xcode | Требуется macOS |
| HarmonyOS | Открыть `apps/harmonyos` в DevEco Studio | Требуется DevEco Studio |

### Тесты

```bash
cd service
vendor/bin/phpunit                    # Модульные тесты (79 tests / 230 assertions)

php tests/im_e2e.php                  # Чёрный ящик E2E для IM (нужны запущенные :8788/:8789 + Redis)
php tests/voice_e2e.php               # Голосовой E2E: версии / голосовые сообщения / звонки / голосовые комнаты
php tests/live_e2e.php                # Стрим-E2E: комнаты / данмаку / микрофоны / закрытие (push RTMP, pull HLS)

cd media/sfu
npm run smoke                         # Смоук-тест протокола SFU /signal (нужен Docker-контейнер или локальный node)
```

## Поддержка приветствуется

Если этот проект вам помог, отсканируйте QR-код и поддержите нас, спасибо!

**WeChat Pay**

<img src="weixinpay.png" width="160" height="175" alt="WeChat Pay">


**Alipay**

<img src="alipay.png" width="160" height="175" alt="Alipay">

**Глобальный перевод (банковский перевод)**




Если проект вам полезен, поддержите разработку банковским переводом по всему миру.

**Информация о получателе**

| Поле | Значение |
|------|------|
| Имя получателя | WANG KEXUN |
| Номер счёта получателя | 881015918251 |

**Банк получателя — ZA Bank**

| Поле | Значение |
|------|------|
| SWIFT Code | AABLHKHHXXX |
| Название банка | ZA Bank Limited |
| Код банка | 387 |
| Адрес банка | Core F, Cyberport 3, 100 Cyberport Road, Hong Kong |

**Банк-корреспондент для трансграничных переводов (при необходимости)**

> Ниже приведены данные банка-корреспондента (банка-посредника) для трансграничных переводов, а не банка получателя. Уточните в банке-отправителе, нужны ли данные банка-корреспондента.

Банк-корреспондент для переводов в гонконгских долларах, юанях и долларах США — **Citibank**:

| Поле | Значение |
|------|------|
| Название банка | Citibank N.A. Hong Kong |
| SWIFT Code | CITIHKHXXXX |
| Код банка | 006 |
| Название филиала | Hong Kong Branch |
| Код филиала | 391 |
| Адрес банка | Citibank Tower, Citibank Plaza, 3 Garden Road, Central, Hong Kong |

Для переводов в других валютах банк-корреспондент — **BNY Mellon**:

| Поле | Значение |
|------|------|
| Название банка | THE BANK OF NEW YORK MELLON |
| SWIFT Code | IRVTUS3NXXX |
| Адрес банка | THE BANK OF NEW YORK MELLON, 240 GREENWICH STREET, NEW YORK, United States |

### Пожертвование в криптовалюте (Crypto Donation)

Если этот проект помог вам, отсканируйте QR-код, чтобы сделать пожертвование, спасибо!

| Сеть (Network) | QR-код (QR Code) | Адрес кошелька (Wallet Address) |
|---|---|---|
| BNB Smart Chain (BEP20) | [<img src="coin/1.jpg" width="150" alt="BNB Smart Chain (BEP20)">](coin/1.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Tron (TRC20) | [<img src="coin/2.jpg" width="150" alt="Tron (TRC20)">](coin/2.jpg) | `TEdDHWLajt1XvqtPDWmQctdrJaC3pzZZzz` |
| Ethereum (ERC20) | [<img src="coin/3.jpg" width="150" alt="Ethereum (ERC20)">](coin/3.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Aptos | [<img src="coin/4.jpg" width="150" alt="Aptos">](coin/4.jpg) | `0x836e3780edfc3f7b2372b39e2a1a3a5d7adfaccd96c726f21cfde1b50dd68030` |
| Plasma | [<img src="coin/5.jpg" width="150" alt="Plasma">](coin/5.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Polygon POS | [<img src="coin/6.jpg" width="150" alt="Polygon POS">](coin/6.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| Solana | [<img src="coin/7.jpg" width="150" alt="Solana">](coin/7.jpg) | `2hfhboHdmdrYsY25XfQSsEWxq5ip4EQsR7f4AzSRMUyr` |
| The Open Network (TON) | [<img src="coin/8.jpg" width="150" alt="The Open Network (TON)">](coin/8.jpg) | `UQB9kFQohzmXUir9QSSZq01iwl9aQZIDdBpNmDklljRtCoGK` |
| Arbitrum One | [<img src="coin/9.jpg" width="150" alt="Arbitrum One">](coin/9.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |
| AVAX C-Chain | [<img src="coin/10.jpg" width="150" alt="AVAX C-Chain">](coin/10.jpg) | `0x355d429f97511897ccb4e271ec888205f9ab6629` |

## Документация

- Общий дизайн: `superpowers/specs/2026-08-16-social-platform-design.md`
- Голосовой дизайн M4: `superpowers/specs/2026-08-17-m4-voice-design.md`
- План реализации: `superpowers/plans/2026-08-17-m4-voice.md`
