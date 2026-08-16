// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';

enum CaptchaType { click, rotate, slider }

class CaptchaService {
  final Dio _dio;
  CaptchaService(this._dio);

  Future<CaptchaData> generate({String difficulty = 'medium'}) async {
    final resp = await _dio.post('/api/captcha/generate', data: {'difficulty': difficulty});
    if (resp.data['code'] != 0) throw Exception(resp.data['message']);
    return CaptchaData.fromJson(resp.data['data']);
  }

  Future<bool> verify(CaptchaData data, dynamic answer) async {
    final body = <String, dynamic>{
      'key': data.key,
      'type': data.type.name,
    };
    // click → array of {x,y}; slider/rotate → plain number
    if (answer is List) {
      body['clicks'] = answer.map((c) => [c.dx.round(), c.dy.round()]).toList();
    } else {
      body['clicks'] = (answer as num).round();
    }
    final resp = await _dio.post('/api/captcha/verify', data: body);
    return resp.data['code'] == 0;
  }
}

class CaptchaData {
  final String key;
  final CaptchaType type;
  final Uint8List imageBytes;
  final Map<String, dynamic> extra;

  CaptchaData._({required this.key, required this.type, required this.imageBytes, required this.extra});

  static Uint8List _img(String raw) =>
      base64Decode(raw.replaceFirst(RegExp(r'^data:image/\w+;base64,'), ''));

  factory CaptchaData.fromJson(Map<String, dynamic> json) {
    final t = json['type'] as String? ?? 'click';
    return CaptchaData._(
      key: json['key'] as String,
      type: t == 'rotate' ? CaptchaType.rotate : t == 'slider' ? CaptchaType.slider : CaptchaType.click,
      imageBytes: _img(json['image'] as String),
      extra: (json['extra'] is Map<String, dynamic>) ? json['extra'] : <String, dynamic>{},
    );
  }

  List<Map<String, dynamic>> get targets {
    final v = extra['targets'];
    return (v is List) ? v.cast<Map<String, dynamic>>() : [];
  }

  int get sliderX => (extra['x'] as num?)?.toInt() ?? 0;
  int get puzzleW => (extra['puzzle_w'] as num?)?.toInt() ?? 50;
  int get sliderY => (extra['y'] as num?)?.toInt() ?? 60;
  int get puzzleH => (extra['puzzle_h'] as num?)?.toInt() ?? 50;
  Uint8List? get puzzleBytes {
    final p = extra['puzzle'];
    return p is String ? _img(p) : null;
  }
}
