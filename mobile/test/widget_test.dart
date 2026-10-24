import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_repository.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/dashboard/dashboard_repository.dart';
import 'package:lucky_care/main.dart';

import 'support/test_doubles.dart';

/// Returns immediately so the router test never touches the network.
class _EmptyDashboardRepository implements DashboardRepository {
  @override
  Future<DashboardData> load() async => DashboardData.absent;
}

void main() {
  testWidgets(
    'an unauthenticated cold start shows the login screen',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authRepositoryProvider.overrideWithValue(FakeAuthRepository()),
          ],
          child: const LuckyCareApp(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('LuckyCare+'), findsOneWidget);
      expect(find.text('เข้าสู่ระบบ'), findsWidgets);
      expect(find.byKey(const Key('login-email')), findsOneWidget);
      expect(find.byKey(const Key('login-password')), findsOneWidget);
      expect(find.byKey(const Key('login-submit')), findsOneWidget);
      // The dashboard must not be built (and must not hit the network) while
      // there is no token.
      expect(find.byKey(const Key('dashboard-loading')), findsNothing);
    },
  );

  testWidgets(
    'a successful login redirects to the dashboard and sign-out returns to login',
    (WidgetTester tester) async {
      final FakeAuthRepository repository = FakeAuthRepository(
        session: testSession(token: 'token-ui'),
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authRepositoryProvider.overrideWithValue(repository),
            dashboardRepositoryProvider.overrideWithValue(
              _EmptyDashboardRepository(),
            ),
          ],
          child: const LuckyCareApp(),
        ),
      );
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('login-email')),
        'somchai@example.com',
      );
      await tester.enterText(
        find.byKey(const Key('login-password')),
        '1234567890',
      );

      await tester.tap(find.byKey(const Key('login-submit')));
      await tester.pumpAndSettle();

      expect(repository.loginCalls, 1);
      expect(find.byKey(const Key('login-email')), findsNothing);
      expect(find.byKey(const Key('dashboard-no-workspace')), findsOneWidget);

      await tester.tap(find.byKey(const Key('logout-button')));
      await tester.pumpAndSettle();

      expect(repository.logoutCalls, 1);
      expect(find.byKey(const Key('login-email')), findsOneWidget);
    },
  );
}
