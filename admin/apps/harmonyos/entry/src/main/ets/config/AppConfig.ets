/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 应用环境配置
 *
 * 切换环境：修改下方 CURRENT 即可
 *   Environment.DEV  → 开发环境（模拟器 localhost）
 *   Environment.TEST → 测试环境
 *   Environment.PROD → 正式环境
 */

export enum Environment {
  DEV = 'dev',
  TEST = 'test',
  PROD = 'prod',
}

const API_HOSTS: Record<Environment, string> = {
  [Environment.DEV]:  'http://10.0.2.2:8791',   // 模拟器访问宿主机
  [Environment.TEST]: 'https://test-api.example.com',
  [Environment.PROD]: 'https://api.example.com',
}

/** 当前环境 — 修改此处切换 */
const CURRENT: Environment = Environment.DEV

class AppConfig {
  static readonly env: Environment = CURRENT

  static get baseUrl(): string { return API_HOSTS[AppConfig.env] }

  static get envName(): string { return AppConfig.env }

  static get isProd(): boolean { return AppConfig.env === Environment.PROD }
}

export default AppConfig
