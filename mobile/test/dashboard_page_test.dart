import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/dashboard/dashboard_page.dart';
import 'package:lucky_care/features/dashboard/widgets/alerts_banner.dart';
import 'package:lucky_care/features/dashboard/widgets/family_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/next_appointment_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/tasks_today_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/vitals_zone.dart';

/// A controller that returns a fixed payload without touching the network.
class _StubDashboardController extends DashboardController {
  _StubDashboardController(this._data);

  final DashboardData _data;

  @override
  Future<DashboardData> build() async => _data;
}

/// A controller that fails the way the aggregate fails for a forbidden read.
class _FailingDashboardController extends DashboardController {
  _FailingDashboardController(this._failure);

  final ApiFailure _failure;

  @override
  Future<DashboardData> build() async => throw _failure;
}

DashboardPayload _payload({
  bool vitalsVisible = false,
  bool appointmentVisible = false,
  bool alertsVisible = true,
  List<AlertItem> alerts = const <AlertItem>[],
}) {
  return DashboardPayload(
    family: const FamilyZone(
      visible: true,
      members: <FamilyMember>[
        FamilyMember(
          personId: 'person-1',
          profileId: 'profile-1',
          displayName: 'คุณพ่อ',
          ring: 'inner',
          status: 'red',
          reason: 'critical_vital',
        ),
      ],
    ),
    alerts: AlertsZone(visible: alertsVisible, items: alerts),
    tasksToday: const TasksTodayZone(
      visible: true,
      total: 6,
      done: 3,
      overdue: 1,
    ),
    vitals: VitalsZone(
      visible: vitalsVisible,
      cards: vitalsVisible
          ? const <VitalCard>[
              VitalCard(
                type: 'blood_pressure',
                status: 'high',
                value: 150,
                secondaryValue: 95,
                unit: 'mmHg',
              ),
            ]
          : const <VitalCard>[],
    ),
    nextAppointment: NextAppointmentZone(
      visible: appointmentVisible,
      title: appointmentVisible ? 'ตรวจติดตามอาการ' : null,
      scheduledAt: appointmentVisible ? '2026-10-25T09:00:00+07:00' : null,
    ),
  );
}

Widget _harness(Override override) {
  return ProviderScope(
    overrides: [override],
    child: MaterialApp(theme: buildAppTheme(), home: const DashboardPage()),
  );
}

void main() {
  testWidgets('D-T06 the dashboard renders only zones whose visible is true',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      _harness(
        dashboardControllerProvider.overrideWith(
          () => _StubDashboardController(
            DashboardData(
              workspaceName: 'บ้านใจดี',
              payload: _payload(),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(FamilyZoneWidget.zoneKey), findsOneWidget);
    expect(find.byKey(TasksTodayZoneWidget.zoneKey), findsOneWidget);
    expect(find.byKey(AlertsBanner.zoneKey), findsNothing);
    expect(find.byKey(VitalsZoneWidget.zoneKey), findsNothing);
    expect(find.byKey(NextAppointmentZoneWidget.zoneKey), findsNothing);

    expect(find.text('คุณพ่อ'), findsOneWidget);
    expect(find.text('สำเร็จ 3/6'), findsOneWidget);
    expect(find.text('เลยกำหนด 1 รายการ'), findsOneWidget);
    expect(find.text('สัญญาณชีพ'), findsNothing);
    expect(find.text('นัดหมายถัดไป'), findsNothing);
  });

  testWidgets('D-T07 a 403 shows the empty state, not an error banner',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      _harness(
        dashboardControllerProvider.overrideWith(
          () => _FailingDashboardController(
            const ApiFailure(
              status: 403,
              code: 'forbidden',
              message: 'คุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('dashboard-empty-state')), findsOneWidget);
    expect(find.byKey(const Key('dashboard-error')), findsNothing);
    expect(find.text('ลองอีกครั้ง'), findsNothing);
    expect(
      find.text('คุณยังไม่มีสิทธิ์เข้าถึงข้อมูลนี้'),
      findsOneWidget,
    );
  });

  testWidgets('an alert banner uses warning for non-critical alerts',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      _harness(
        dashboardControllerProvider.overrideWith(
          () => _StubDashboardController(
            DashboardData(
              workspaceName: 'บ้านใจดี',
              payload: _payload(
                alerts: const <AlertItem>[
                  AlertItem(
                    level: 'warning',
                    code: 'tasks_overdue',
                    messageKey: 'dashboard.alert.tasks_overdue',
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(AlertsBanner.zoneKey), findsOneWidget);
    expect(find.text('มีเรื่องที่ควรตรวจสอบ'), findsOneWidget);
    expect(find.text('มีงานที่เลยกำหนด'), findsOneWidget);
  });
}
