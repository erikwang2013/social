/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'dart:math';
import 'package:dio/dio.dart';
import 'package:get/get.dart' hide Response;
import 'auth_service.dart';
import '../config/app_config.dart';
import '../i18n/locale_service.dart';

class ApiService {
  static final ApiService _instance = ApiService._();
  factory ApiService() => _instance;

  late final Dio dio;
  static String get baseUrl => AppConfig.baseUrl;

  ApiService._() {
    dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
      headers: {
        'Content-Type': 'application/json',
        'API-Version': 'v1',
      },
    ));

    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await AuthService.getToken();
        print('ApiService.onRequest: token=${token != null ? token.substring(0, min(20, token.length)) : "null"} url=${options.uri}');
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        try {
          final svc = Get.find<LocaleService>();
          options.headers['Accept-Language'] = svc.langCode;
        } catch (_) {}
        handler.next(options);
      },
      onError: (error, handler) async {
        if (error.response?.statusCode == 401) {
          final refreshed = await tryRefresh();
          if (!refreshed) {
            await AuthService.clearToken();
            Future.microtask(() => Get.offAllNamed('/login'));
          }
        }
        handler.next(error);
      },
    ));
  }

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? params}) async {
    final resp = await dio.get(path, queryParameters: params);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> post(String path, {dynamic data}) async {
    final resp = await dio.post(path, data: data);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> put(String path, {dynamic data}) async {
    final resp = await dio.put(path, data: data);
    return _handleResponse(resp);
  }

  Future<Map<String, dynamic>> delete(String path, {dynamic data}) async {
    final resp = await dio.delete(path, data: data);
    return _handleResponse(resp);
  }

  Map<String, dynamic> _handleResponse(Response resp) {
    final body = resp.data as Map<String, dynamic>;
    if (body['code'] != 0) {
      throw ApiException(body['code'] as int, body['message'] as String? ?? '请求失败');
    }
    return body;
  }

  Future<bool> tryRefresh() async {
    final refreshToken = await AuthService.getRefreshToken();
    if (refreshToken == null) return false;
    try {
      final resp = await dio.post('/api/auth/refresh', data: {'refresh_token': refreshToken});
      final data = resp.data['data'];
      if (resp.data['code'] == 0) {
        await AuthService.saveLogin(
          token: data['access_token'],
          refreshToken: data['refresh_token'],
          username: data['user']?['username'] ?? '',
        );
        return true;
      }
    } catch (_) {}
    return false;
  }
}

class ApiException implements Exception {
  final int code;
  final String message;
  ApiException(this.code, this.message);

  @override
  String toString() => 'ApiException($code): $message';
}
