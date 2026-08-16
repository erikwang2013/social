// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import '../services/api_service.dart';
import '../services/captcha_service.dart';
import '../i18n/translations.dart';
import '../pages/login/captcha/click.dart';
import '../pages/login/captcha/slider.dart';
import '../pages/login/captcha/rotate.dart';

class CaptchaDialog extends StatefulWidget {
  final CaptchaService svc;
  final GlobalKey<ClickCaptchaState> clickKey;
  final GlobalKey<SliderCaptchaState> sliderKey;
  final GlobalKey<RotateCaptchaState> rotateKey;

  const CaptchaDialog._({
    required this.svc,
    required this.clickKey,
    required this.sliderKey,
    required this.rotateKey,
  });

  /// 显示验证码弹框，返回 captcha key（取消返回 null）
  static Future<String?> show(BuildContext context) {
    final svc = CaptchaService(Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: {'API-Version': 'v1'})));
    final clickKey = GlobalKey<ClickCaptchaState>();
    final sliderKey = GlobalKey<SliderCaptchaState>();
    final rotateKey = GlobalKey<RotateCaptchaState>();

    return showDialog<String>(
      context: context,
      barrierDismissible: false,
      builder: (_) => CaptchaDialog._(
        svc: svc,
        clickKey: clickKey,
        sliderKey: sliderKey,
        rotateKey: rotateKey,
      ),
    );
  }

  @override
  State<CaptchaDialog> createState() => _CaptchaDialogState();
}

class _CaptchaDialogState extends State<CaptchaDialog> {
  CaptchaData? _data;
  String? _error;
  bool _verifying = false;

  @override
  void initState() { super.initState(); _reload(); }

  Future<void> _reload() async {
    try {
      _data = await widget.svc.generate();
      if (mounted) setState(() => _error = null);
    } catch (e) { if (mounted) setState(() => _error = '${t('captcha_load_fail')}: $e'); }
  }

  Future<void> _confirm() async {
    if (_data == null) return;
    setState(() => _verifying = true);

    try {
      dynamic answer;
      switch (_data!.type) {
        case CaptchaType.click: answer = widget.clickKey.currentState!.answer; break;
        case CaptchaType.slider: answer = widget.sliderKey.currentState!.answer; break;
        case CaptchaType.rotate: answer = widget.rotateKey.currentState!.answer; break;
      }
      final ok = await widget.svc.verify(_data!, answer);
      if (ok) {
        if (mounted) Navigator.pop(context, _data!.key);
      } else {
        if (mounted) { setState(() => _error = t('captcha_verify_fail')); _reload(); }
      }
    } catch (_) {
      if (mounted) { setState(() => _error = t('captcha_network_error')); _reload(); }
    } finally {
      if (mounted) setState(() => _verifying = false);
    }
  }

  Widget _captchaUI() {
    if (_data == null) return const Center(child: CircularProgressIndicator());
    switch (_data!.type) {
      case CaptchaType.slider: return SliderCaptcha(key: widget.sliderKey, data: _data!);
      case CaptchaType.rotate: return RotateCaptcha(key: widget.rotateKey, data: _data!);
      default: return ClickCaptcha(key: widget.clickKey, data: _data!);
    }
  }

  @override
  Widget build(BuildContext c) => AlertDialog(
    title: Row(children: [const Icon(Icons.security), const SizedBox(width: 8), Text(t('captcha_title'))]),
    content: SizedBox(
      width: 360,
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        _captchaUI(),
        const SizedBox(height: 8),
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          TextButton.icon(onPressed: _reload, icon: const Icon(Icons.refresh, size: 16), label: Text(t('captcha_refresh'))),
          if (_error != null)
            Flexible(child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 12), textAlign: TextAlign.right)),
        ]),
      ]),
    ),
    actions: [
      TextButton(onPressed: _verifying ? null : () => Navigator.pop(context), child: Text(t('cancel'))),
      FilledButton(
        onPressed: _verifying ? null : _confirm,
        child: _verifying ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(t('captcha_confirm')),
      ),
    ],
  );
}
