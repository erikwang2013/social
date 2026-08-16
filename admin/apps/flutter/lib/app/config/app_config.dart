/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/// 应用环境配置
///
/// 切换环境：修改下方 [current] 的值即可
///   Environment.dev  → 开发环境
///   Environment.test → 测试环境
///   Environment.prod → 正式环境
///
/// 编译时也可通过参数覆盖:
///   flutter run --dart-define=ENV=test
///   flutter build web --dart-define=ENV=prod

enum Environment { dev, test, prod }

class AppConfig {
  AppConfig._();

  /// 当前环境 — 修改此处切换
  static const Environment current = Environment.dev;

  /// 编译参数 --dart-define=ENV=xxx 可覆盖 [current]
  static Environment get env {
    const fromDefine = String.fromEnvironment('ENV');
    switch (fromDefine) {
      case 'test':
        return Environment.test;
      case 'prod':
        return Environment.prod;
      default:
        return current;
    }
  }

  /// 各环境 API 域名
  static const Map<Environment, String> _baseUrls = {
    Environment.dev:  'http://localhost:8791',
    Environment.test: 'https://test-api.example.com',
    Environment.prod: 'https://api.example.com',
  };

  /// 当前 API 基础地址
  static String get baseUrl => _baseUrls[env]!;

  /// 环境名称
  static String get envName => env.name;

  /// 是否为正式环境
  static bool get isProd => env == Environment.prod;
}
