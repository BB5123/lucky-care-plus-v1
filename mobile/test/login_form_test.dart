import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_repository.dart';
import 'package:lucky_care/main.dart';

import 'support/test_doubles.dart';

void main() {
  testWidgets(
    'D-T05 login form blocks an invalid email and a short password before '
    'calling the repository',
    (WidgetTester tester) async {
      final FakeAuthRepository repository = FakeAuthRepository();

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authRepositoryProvider.overrideWithValue(repository),
          ],
          child: const LuckyCareApp(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('login-email')),
        'not-an-email',
      );
      await tester.enterText(
        find.byKey(const Key('login-password')),
        'short',
      );

      await tester.tap(find.byKey(const Key('login-submit')));
      await tester.pumpAndSettle();

      expect(repository.loginCalls, 0);
      expect(find.text('รูปแบบอีเมลไม่ถูกต้อง'), findsOneWidget);
      expect(
        find.text('รหัสผ่านต้องมีอย่างน้อย 10 ตัวอักษร'),
        findsOneWidget,
      );
    },
  );
}
