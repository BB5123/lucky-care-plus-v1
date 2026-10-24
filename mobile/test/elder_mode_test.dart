import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/core/notifications/notification_models.dart';
import 'package:lucky_care/core/preferences/elder_mode.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/design_system/tokens.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/dashboard/dashboard_page.dart';
import 'package:lucky_care/features/dashboard/elder_focus.dart';
import 'package:lucky_care/features/dashboard/widgets/alerts_banner.dart';
import 'package:lucky_care/features/dashboard/widgets/elder_focus_card.dart';
import 'package:lucky_care/features/dashboard/widgets/family_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/next_appointment_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/tasks_today_zone.dart';
import 'package:lucky_care/features/dashboard/widgets/vitals_zone.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/notifications/notifications_controller.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/main.dart';

import 'support/elder_mode_doubles.dart';
import 'support/tab_stubs.dart';

/// A dashboard controller that answers from memory.
class _StubDashboardController extends DashboardController {
  _StubDashboardController(this._data);

  final DashboardData _data;

  @override
  Future<DashboardData> build() async => _data;
}

DashboardPayload _payload({
  List<TaskCard> tasks = const <TaskCard>[],
  bool alertsVisible = false,
  List<AlertItem> alerts = const <AlertItem>[],
  String? appointmentTitle,
  String? appointmentAt,
  bool vitalsVisible = true,
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
    tasksToday: TasksTodayZone(
      visible: true,
      total: tasks.length,
      done: 0,
      overdue: 0,
      items: tasks,
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
      visible: appointmentTitle != null,
      title: appointmentTitle,
      scheduledAt: appointmentAt,
    ),
  );
}

DashboardData _dashboard(DashboardPayload payload) {
  return DashboardData(workspaceName: 'บ้านใจดี', payload: payload);
}

TaskCard _task({
  required String id,
  required String title,
  String status = 'open',
  String? dueAt,
}) {
  return TaskCard(
    id: id,
    title: title,
    status: status,
    priority: 'normal',
    dueAt: dueAt,
  );
}

Future<ProviderContainer> _pumpDashboard(
  WidgetTester tester, {
  required DashboardData data,
  required FakeElderModeStore store,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        elderModeStoreProvider.overrideWithValue(store),
        dashboardControllerProvider.overrideWith(
          () => _StubDashboardController(data),
        ),
      ],
      child: MaterialApp(theme: buildAppTheme(), home: const DashboardPage()),
    ),
  );
  await tester.pumpAndSettle();

  final ProviderContainer container = ProviderScope.containerOf(
    tester.element(find.byType(DashboardPage)),
  );

  await container.read(elderModeControllerProvider.notifier).restore();
  await tester.pumpAndSettle();

  return container;
}

void main() {
  test('H-T01 the controller persists true and restores it on boot', () async {
    final FakeElderModeStore store = FakeElderModeStore();

    final ProviderContainer first = ProviderContainer.test(
      overrides: [elderModeStoreProvider.overrideWithValue(store)],
    );
    final ElderModeController controller = first.read(
      elderModeControllerProvider.notifier,
    );

    expect(controller.enabled, isFalse);

    await controller.enable();

    expect(controller.enabled, isTrue);
    expect(store.value, isTrue);
    expect(store.writes, 1);

    // A new container models a cold start: the value comes from the store.
    final ProviderContainer boot = ProviderContainer.test(
      overrides: [elderModeStoreProvider.overrideWithValue(store)],
    );

    expect(boot.read(elderModeControllerProvider), isFalse);

    await boot.read(elderModeControllerProvider.notifier).restore();

    expect(boot.read(elderModeControllerProvider), isTrue);
    expect(store.reads, 1);

    await controller.disable();

    expect(controller.enabled, isFalse);
    expect(store.value, isFalse);
  });

  test('elder mode bumps the Material body style one token step', () {
    final ThemeData normal = buildAppTheme();
    final ThemeData elder = buildAppTheme(elderMode: true);

    expect(elder.textTheme.bodyMedium?.fontSize, AppType.titleSize);
    expect(elder.textTheme.bodyMedium!.fontSize, greaterThanOrEqualTo(20));
    expect(
      normal.textTheme.bodyMedium?.fontSize ?? 0,
      lessThan(AppType.titleSize),
    );
  });

  testWidgets(
    'H-T02 toggling the shell switch updates the dashboard without a restart',
    (WidgetTester tester) async {
      final FakeElderModeStore store = FakeElderModeStore();
      final DashboardData data = _dashboard(
        _payload(
          tasks: <TaskCard>[
            _task(
              id: 'task-1',
              title: 'กินยาหลังอาหารเช้า',
              dueAt: '2026-10-24T08:00:00+07:00',
            ),
          ],
        ),
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            elderModeStoreProvider.overrideWithValue(store),
            authControllerProvider.overrideWith(
              AuthenticatedAuthController.new,
            ),
            dashboardControllerProvider.overrideWith(
              () => StubDashboardController(data),
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

      expect(find.byKey(ElderFocusCard.cardKey), findsNothing);
      expect(find.byKey(TasksTodayZoneWidget.zoneKey), findsOneWidget);

      await tester.tap(find.byKey(const Key('elder-mode-button')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('elder-mode-switch')), findsOneWidget);
      expect(find.text('โหมดหน้าจอย่อ'), findsOneWidget);

      await tester.tap(find.byKey(const Key('elder-mode-switch')));
      await tester.pumpAndSettle();

      expect(find.byKey(ElderFocusCard.cardKey), findsOneWidget);
      expect(find.byKey(TasksTodayZoneWidget.zoneKey), findsNothing);
      expect(find.text('กินยาหลังอาหารเช้า'), findsOneWidget);
      expect(store.value, isTrue);
    },
  );

  testWidgets(
    'H-T03 elder mode renders one focus card and no vitals chart or table',
    (WidgetTester tester) async {
      final FakeElderModeStore store = FakeElderModeStore(value: true);

      await _pumpDashboard(
        tester,
        store: store,
        data: _dashboard(
          _payload(
            tasks: <TaskCard>[
              _task(
                id: 'task-1',
                title: 'กินยาหลังอาหารเช้า',
                dueAt: '2026-10-24T08:00:00+07:00',
              ),
            ],
            appointmentTitle: 'ตรวจติดตามอาการ',
            appointmentAt: '2026-10-25T09:00:00+07:00',
          ),
        ),
      );

      expect(find.byKey(ElderFocusCard.cardKey), findsOneWidget);
      expect(find.byKey(ElderFocusCard.familyStatusKey), findsOneWidget);
      expect(find.byKey(VitalsZoneWidget.zoneKey), findsNothing);
      expect(find.byKey(FamilyZoneWidget.zoneKey), findsNothing);
      expect(find.byKey(TasksTodayZoneWidget.zoneKey), findsNothing);
      expect(find.byKey(NextAppointmentZoneWidget.zoneKey), findsNothing);
      expect(find.byKey(AlertsBanner.zoneKey), findsNothing);
      expect(find.byType(GridView), findsNothing);
      expect(find.text('สัญญาณชีพ'), findsNothing);

      final Text familyLine = tester.widget<Text>(
        find.byKey(ElderFocusCard.familyStatusKey),
      );

      expect(familyLine.maxLines, 1);
    },
  );

  testWidgets('H-T04 elder mode renders at most three buttons',
      (WidgetTester tester) async {
    final FakeElderModeStore store = FakeElderModeStore(value: true);

    await _pumpDashboard(
      tester,
      store: store,
      data: _dashboard(
        _payload(
          tasks: <TaskCard>[
            _task(
              id: 'task-1',
              title: 'กินยาหลังอาหารเช้า',
              dueAt: '2026-10-24T08:00:00+07:00',
            ),
          ],
          appointmentTitle: 'ตรวจติดตามอาการ',
          appointmentAt: '2026-10-25T09:00:00+07:00',
        ),
      ),
    );

    final int buttons = find
        .byWidgetPredicate(
          (Widget widget) =>
              widget is ButtonStyleButton || widget is IconButton,
        )
        .evaluate()
        .length;

    expect(buttons, lessThanOrEqualTo(3));
    expect(find.byKey(ElderFocusCard.primaryActionKey), findsOneWidget);

    final FilledButton primary = tester.widget<FilledButton>(
      find.byKey(ElderFocusCard.primaryActionKey),
    );

    expect(primary.onPressed, isNotNull);
  });

  test('H-T05 the primary action follows task -> appointment -> record',
      () {
    final ElderFocusAction taskAction = selectElderFocusAction(
      _payload(
        tasks: <TaskCard>[
          _task(
            id: 'late',
            title: 'งานตอนเย็น',
            dueAt: '2026-10-24T18:00:00+07:00',
          ),
          _task(
            id: 'done',
            title: 'งานที่เสร็จแล้ว',
            status: 'completed',
            dueAt: '2026-10-24T07:00:00+07:00',
          ),
          _task(
            id: 'early',
            title: 'งานตอนเช้า',
            dueAt: '2026-10-24T08:00:00+07:00',
          ),
        ],
        appointmentTitle: 'ตรวจติดตามอาการ',
        appointmentAt: '2026-10-25T09:00:00+07:00',
      ),
    );

    expect(taskAction.kind, ElderFocusKind.task);
    expect(taskAction.title, 'งานตอนเช้า');
    expect(taskAction.label, 'เปิดงานดูแล');
    expect(taskAction.route, '/tasks');

    final ElderFocusAction appointmentAction = selectElderFocusAction(
      _payload(
        appointmentTitle: 'ตรวจติดตามอาการ',
        appointmentAt: '2026-10-25T09:00:00+07:00',
      ),
    );

    expect(appointmentAction.kind, ElderFocusKind.appointment);
    expect(appointmentAction.title, 'นัดหมาย: ตรวจติดตามอาการ');
    expect(appointmentAction.label, 'ดูนัดหมาย');
    expect(appointmentAction.route, '/appointments');

    final ElderFocusAction recordAction = selectElderFocusAction(_payload());

    expect(recordAction.kind, ElderFocusKind.record);
    expect(recordAction.label, 'บันทึกค่า');
    expect(recordAction.route, '/health');
  });

  testWidgets('H-T05 the record action opens the health tab',
      (WidgetTester tester) async {
    final FakeElderModeStore store = FakeElderModeStore(value: true);
    final ProviderContainer container = ProviderContainer(
      overrides: [
        elderModeStoreProvider.overrideWithValue(store),
        authControllerProvider.overrideWith(AuthenticatedAuthController.new),
        dashboardControllerProvider.overrideWith(
          () => StubDashboardController(_dashboard(_payload())),
        ),
        healthControllerProvider.overrideWith(
          () => StubHealthController(
            HealthData(
              hasProfile: true,
              measurements: const <HealthMeasurement>[],
            ),
          ),
        ),
        notificationDeliveriesControllerProvider.overrideWith(
          () => StubNotificationDeliveriesController(
            const <NotificationDelivery>[],
          ),
        ),
      ],
    );
    addTearDown(container.dispose);

    await container.read(elderModeControllerProvider.notifier).restore();

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: const LuckyCareApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(ElderFocusCard.cardKey), findsOneWidget);
    expect(find.text('บันทึกค่า'), findsOneWidget);

    await tester.tap(find.byKey(ElderFocusCard.primaryActionKey));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('health-page')), findsOneWidget);
    expect(find.byKey(ElderFocusCard.cardKey), findsNothing);
  });

  testWidgets(
    'H-T06 disabling elder mode restores the five zones',
    (WidgetTester tester) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      final FakeElderModeStore store = FakeElderModeStore(value: true);

      final ProviderContainer container = await _pumpDashboard(
        tester,
        store: store,
        data: _dashboard(
          _payload(
            tasks: <TaskCard>[
              _task(
                id: 'task-1',
                title: 'กินยาหลังอาหารเช้า',
                dueAt: '2026-10-24T08:00:00+07:00',
              ),
            ],
            alertsVisible: true,
            alerts: const <AlertItem>[
              AlertItem(
                level: 'warning',
                code: 'tasks_overdue',
                messageKey: 'dashboard.alert.tasks_overdue',
              ),
            ],
            appointmentTitle: 'ตรวจติดตามอาการ',
            appointmentAt: '2026-10-25T09:00:00+07:00',
          ),
        ),
      );

      expect(find.byKey(ElderFocusCard.cardKey), findsOneWidget);
      expect(find.byKey(FamilyZoneWidget.zoneKey), findsNothing);

      await container.read(elderModeControllerProvider.notifier).disable();
      await tester.pumpAndSettle();

      expect(find.byKey(ElderFocusCard.cardKey), findsNothing);
      expect(find.byKey(FamilyZoneWidget.zoneKey), findsOneWidget);
      expect(find.byKey(AlertsBanner.zoneKey), findsOneWidget);
      expect(find.byKey(TasksTodayZoneWidget.zoneKey), findsOneWidget);
      expect(find.byKey(VitalsZoneWidget.zoneKey), findsOneWidget);
      expect(find.byKey(NextAppointmentZoneWidget.zoneKey), findsOneWidget);
      expect(store.value, isFalse);
    },
  );
}
