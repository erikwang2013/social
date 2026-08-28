// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
import 'package:flutter_test/flutter_test.dart';
import 'package:get/get.dart';
import 'package:admin_app/main.dart';
import 'package:admin_app/app/i18n/locale_service.dart';
import 'package:admin_app/app/pages/login/login_page.dart';

void main() {
  testWidgets('Admin app smoke test', (WidgetTester tester) async {
    // 基线缺陷：测试直接 pumpWidget 跳过了 main() 的 GetX 初始化
    Get.put(LocaleService());
    await tester.pumpWidget(const AdminApp());
    await tester.pumpAndSettle();
    expect(find.byType(LoginPage), findsOneWidget);
  });
}
