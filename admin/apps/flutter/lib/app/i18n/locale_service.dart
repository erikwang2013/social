/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:shared_preferences/shared_preferences.dart';

class LocaleService extends GetxService {
  static const _key = 'app_locale';
  static const supported = [Locale('zh', 'CN'), Locale('en')];

  final locale = Locale('zh', 'CN').obs;

  String get langCode => locale.value.languageCode == 'en' ? 'en' : 'zh_CN';

  @override
  void onInit() {
    super.onInit();
    _load();
  }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final saved = prefs.getString(_key);
    if (saved != null) {
      final parts = saved.split('_');
      locale.value = Locale(parts[0], parts.length > 1 ? parts[1] : '');
      Get.updateLocale(locale.value);
    }
  }

  Future<void> setLocale(Locale loc) async {
    locale.value = loc;
    Get.updateLocale(loc);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, '${loc.languageCode}_${loc.countryCode ?? ''}');
  }

  Future<void> toggle() async {
    final next = locale.value.languageCode == 'zh' ? const Locale('en') : const Locale('zh', 'CN');
    await setLocale(next);
  }
}
