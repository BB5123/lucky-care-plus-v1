import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/core/notifications/notification_models.dart';
import 'package:lucky_care/core/workspace/retention_status.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/notifications/notifications_controller.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/main.dart';

import 'support/tab_stubs.dart';

/// The shell reads the account state; every widget test supplies it explicitly
/// instead of letting the repository reach the network.
List<Override> _overrides({RetentionStatus retention = RetentionStatus.none}) {
  return <Override>[
    authControllerProvider.overrideWith(AuthenticatedAuthController.new),
    dashboardControllerProvider.overrideWith(
      () => StubDashboardController(DashboardData.absent),
    ),
    healthControllerProvider.overrideWith(
      () => StubHealthController(
        HealthData(hasProfile: true, measurements: const <HealthMeasurement>[]),
      ),
    ),
    tasksControllerProvider.overrideWith(
      () => StubTasksController(
        TasksData(hasWorkspace: true, tasks: const <CareTask>[]),
      ),
    ),
    appointmentsControllerProvider.overrideWith(
      () => StubAppointmentsController(
        AppointmentsData(hasProfile: true, appointments: const <Appointment>[]),
      ),
    ),
    familyControllerProvider.overrideWith(
      () => StubFamilyController(
        FamilyData(hasWorkspace: true, members: const <FamilyMembership>[]),
      ),
    ),
    notificationDeliveriesControllerProvider.overrideWith(
      () =>
          StubNotificationDeliveriesController(const <NotificationDelivery>[]),
    ),
    workspaceRetentionProvider.overrideWith((Ref ref) async => retention),
  ];
}

void main() {
  testWidgets(
    'D2-T01 the shell shows five destinations and switching tabs keeps the '
    'auth state',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        ProviderScope(overrides: _overrides(), child: const LuckyCareApp()),
      );
      await tester.pumpAndSettle();

      final Finder navigation = find.byType(NavigationBar);

      expect(navigation, findsOneWidget);
      expect(find.byKey(const Key('dashboard-page')), findsOneWidget);
      expect(find.byKey(const Key('login-email')), findsNothing);
      expect(find.byKey(const Key('retention-banner')), findsNothing);

      for (final String label in <String>[
        'ภาพรวม',
        'สุขภาพ',
        'งาน',
        'นัดหมาย',
        'ครอบครัว',
      ]) {
        expect(
          find.descendant(of: navigation, matching: find.text(label)),
          findsOneWidget,
        );
      }

      await tester.tap(
        find.descendant(of: navigation, matching: find.text('สุขภาพ')),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('health-page')), findsOneWidget);
      expect(find.byKey(const Key('dashboard-page')), findsNothing);
      expect(find.byKey(const Key('login-email')), findsNothing);

      final ProviderContainer container = ProviderScope.containerOf(
        tester.element(navigation),
      );

      expect(container.read(authControllerProvider).isAuthenticated, isTrue);

      await tester.tap(
        find.descendant(of: navigation, matching: find.text('ภาพรวม')),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('dashboard-page')), findsOneWidget);
    },
  );

  testWidgets('RET-M04 the shell shows the account state banner', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _overrides(
          retention: const RetentionStatus(state: RetentionState.readOnly),
        ),
        child: const LuckyCareApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('retention-banner')), findsOneWidget);
    expect(find.textContaining('อ่านอย่างเดียว'), findsOneWidget);
    // The tabs still render underneath: the banner explains, it does not block.
    expect(find.byType(NavigationBar), findsOneWidget);

    // The only banner action is the jump to the export card in the family tab.
    await tester.tap(find.byKey(const Key('retention-banner-export')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('family-page')), findsOneWidget);
    // The banner follows the account, so it stays visible on the other tab.
    expect(find.byKey(const Key('retention-banner')), findsOneWidget);
  });
}
