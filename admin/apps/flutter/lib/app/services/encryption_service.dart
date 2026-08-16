/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

import 'package:encrypt/encrypt.dart';
import 'package:pointycastle/asymmetric/api.dart';

/// 密码传输加密 — RSA 非对称加密（公钥加密，私钥仅服务端持有）
///
/// 公钥可安全存放于前端代码中，不会导致密钥泄露。
/// 加密算法: RSA-2048 / PKCS1v1.5 / Base64
class EncryptionService {
  static const _publicKeyPem = '''-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0+QqkXVytnm32HjL5HVV
G1p6E5oRnQ/WKAaKLisjH0D+tffvVTQ0zIdJS92ohbfI+90SpmsKfgLdEah1gFMT
AyynXbwj9JHIhGjmjYSTC3EPnzmYD9I+K4gjf8E/BE0BdJfQaEb/1d8XduqeUxu2
8LL4q7tmcdH95mhyklUDPy87BADMZpGqGne054wQQDguvhflBYK+6EGJnAkRvj4S
Y9J5x1UIn5f00xR9ZxMVPRHBud12jr+x/udQB5MsFOXoFz007zubefjZauIVWPuV
5IR9KmMYzfqiRbTSOHW+jDuxtDSPgEvCvZlEaB/EhYp1FVbazjuCys4VH6R/dyCy
PwIDAQAB
-----END PUBLIC KEY-----''';

  static RSAPublicKey? _cachedKey;

  static RSAPublicKey get _key {
    _cachedKey ??= RSAKeyParser().parse(_publicKeyPem) as RSAPublicKey;
    return _cachedKey!;
  }

  /// RSA-2048 PKCS1v1.5 加密 → Base64 编码
  static String encrypt(String plaintext) {
    if (plaintext.isEmpty) return '';
    final encrypted = Encrypter(RSA(publicKey: _key)).encrypt(plaintext);
    return encrypted.base64;
  }
}
