// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:get/get.dart';
import '../../services/api_service.dart';
import '../../services/auth_service.dart';
import '../../services/encryption_service.dart';
import '../../widgets/captcha_dialog.dart';
import '../../i18n/translations.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  final _dio = Dio(BaseOptions(baseUrl: ApiService.baseUrl, headers: {'API-Version': 'v1'}));

  bool _loading = false;
  String? _error;

  Future<void> _login() async {
    final u = _userCtrl.text.trim(), p = _passCtrl.text;
    if (u.isEmpty || p.isEmpty) { setState(() => _error = t('login_username_required')); return; }

    setState(() { _loading = true; _error = null; });

    final captchaKey = await CaptchaDialog.show(context);
    if (!mounted) return;
    if (captchaKey == null) { setState(() => _loading = false); return; }

    try {
      final resp = await _dio.post('/api/auth/login', data: {
        'username': u,
        'password': EncryptionService.encrypt(p),
        'captcha_key': captchaKey,
      });
      if (resp.data['code'] == 0) {
        final d = resp.data['data'];
        await AuthService.saveLogin(token: d['access_token'], refreshToken: d['refresh_token'], username: d['user']['username']);
        if (mounted) Get.offAllNamed('/dashboard');
      } else {
        if (mounted) setState(() => _error = resp.data['message'] ?? t('login_failed'));
      }
    } catch (_) {
      if (mounted) setState(() => _error = t('login_network_error'));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() { _userCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext c) => Scaffold(
    backgroundColor: Colors.white,
    body: Center(child: SingleChildScrollView(
      padding: const EdgeInsets.all(32),
      child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 440), child: Column(
        mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.admin_panel_settings, size: 56, color: Color(0xFF1677FF)),
          const SizedBox(height: 8),
          Text(t('login_title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 28),
          TextField(controller: _userCtrl, decoration: InputDecoration(labelText: t('login_username'), prefixIcon: const Icon(Icons.person_outline), border: const OutlineInputBorder())),
          const SizedBox(height: 14),
          TextField(controller: _passCtrl, obscureText: true, decoration: InputDecoration(labelText: t('login_password'), prefixIcon: const Icon(Icons.lock_outline), border: const OutlineInputBorder())),
          const SizedBox(height: 20),
          if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 12),
            child: Container(padding: const EdgeInsets.all(10), decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(6)),
              child: Row(children: [Icon(Icons.error_outline, color: Colors.red.shade400, size: 18), const SizedBox(width: 8), Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)))])),
          ),
          SizedBox(width: double.infinity, height: 44, child: FilledButton(
            onPressed: _loading ? null : _login,
            child: _loading ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(t('login_button'), style: const TextStyle(fontSize: 16)),
          )),
          const SizedBox(height: 16),
          Text(t('login_copyright'), style: TextStyle(fontSize: 11, color: Colors.grey.shade400)),
        ],
      )),
    )),
  );
}
