import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/notifications/notifications_controller.dart';
import 'package:lucky_care/core/notifications/notification_models.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/main.dart';

import 'support/tab_stubs.dart';

void main() {
  testWidgets(
    'D2-T01 the shell shows five destinations and switching tabs keeps the '
    'auth state',
    (WidgetTester tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            authControllerProvider.overrideWith(
              AuthenticatedAuthController.new,
            ),
            dashboardControllerProvider.overrideWith(
              () => StubDashboardController(DashboardData.absent),
            ),
            healthControllerProvider.overrideWith(
              () => StubHealthController(
                HealthData(
                  hasProfile: true,
                  measurements: const <HealthMeasurement>[],
                ),
              ),
            ),
            tasksControllerProvider.overrideWith(
              () => StubTasksController(
                TasksData(hasWorkspace: true, tasks: const <CareTask>[]),
              ),
            ),
            appointmentsControllerProvider.overrideWith(
              () => StubAppointmentsController(
                AppointmentsData(
                  hasProfile: true,
                  appointments: const <Appointment>[],
                ),
              ),
            ),
            familyControllerProvider.overrideWith(
              () => StubFamilyController(
                FamilyData(
                  hasWorkspace: true,
                  members: const <FamilyMembership>[],
                ),
              ),
            ),
            notificationDeliveriesControllerProvider.overrideWith(
              () => StubNotificationDeliveriesController(
                const <NotificationDelivery>[],
              ),
            ),
          ],
          child: const LuckyCareApp(),
        ),
      );
      await tester.pumpAndSettle();

      final Finder navigation = find.byType(NavigationBar);

      expect(navigation, findsOneWidget);
      expect(find.byKey(const Key('dashboard-page')), findsOneWidget);
      expect(find.byKey(const Key('login-email')), findsNothing);

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
}
