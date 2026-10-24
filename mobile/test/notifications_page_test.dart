import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/auth/auth_controller.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/notifications/notification_models.dart';
import 'package:lucky_care/core/notifications/notifications_repository.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/appointments/appointment_models.dart';
import 'package:lucky_care/features/appointments/appointments_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_controller.dart';
import 'package:lucky_care/features/dashboard/dashboard_models.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/notifications/notifications_controller.dart';
import 'package:lucky_care/features/notifications/notifications_page.dart';
import 'package:lucky_care/features/tasks/task_models.dart';
import 'package:lucky_care/features/tasks/tasks_controller.dart';
import 'package:lucky_care/main.dart';

import 'support/tab_stubs.dart';

class _FakeNotificationsRepository implements NotificationsRepository {
  _FakeNotificationsRepository({
    required this.deliveries,
    required this.preferences,
  });

  final List<NotificationDelivery> deliveries;
  NotificationPreferences preferences;

  Completer<NotificationDelivery>? markReadGate;
  ApiFailure? markReadFailure;
  int listCalls = 0;
  int markReadCalls = 0;
  NotificationPreferencesPatch? lastPatch;
  Map<String, dynamic>? lastPatchJson;

  @override
  Future<List<NotificationDelivery>> listDeliveries({
    String? status,
    String? channel,
    bool? unread,
    int? limit,
  }) async {
    listCalls += 1;
    return deliveries;
  }

  @override
  Future<NotificationDelivery> markRead(String id) {
    markReadCalls += 1;

    final Completer<NotificationDelivery>? gate = markReadGate;

    if (gate != null) {
      return gate.future;
    }

    final ApiFailure? failure = markReadFailure;

    if (failure != null) {
      return Future<NotificationDelivery>.error(failure);
    }

    final NotificationDelivery row = deliveries.firstWhere(
      (NotificationDelivery entry) => entry.id == id,
    );

    return Future<NotificationDelivery>.value(
      row.copyWith(readAt: '2026-10-24T08:00:00Z'),
    );
  }

  @override
  Future<NotificationPreferences> getPreferences() async => preferences;

  @override
  Future<NotificationPreferences> patchPreferences(
    NotificationPreferencesPatch changes,
  ) async {
    lastPatch = changes;
    lastPatchJson = changes.toJson();

    final NotificationChannels channels = preferences.channels;
    final Map<String, bool>? changedChannels = changes.channels;
    final NotificationPreferences updated = NotificationPreferences(
      locale: changes.locale ?? preferences.locale,
      channels: NotificationChannels(
        inApp: changedChannels?['in_app'] ?? channels.inApp,
        push: changedChannels?['push'] ?? channels.push,
        line: changedChannels?['line'] ?? channels.line,
      ),
      quietHours: changes.quietHours == null
          ? preferences.quietHours
          : changes.quietHours!.value,
      digest: changes.digest ?? preferences.digest,
      updatedAt: 'saved',
    );
    preferences = updated;

    return updated;
  }
}

NotificationDelivery _delivery({
  required String id,
  required String templateKey,
  String status = 'sent',
  String? readAt,
  String channel = 'in_app',
  String createdAt = '2026-10-24T08:00:00Z',
}) {
  return NotificationDelivery(
    id: id,
    channel: channel,
    templateKey: templateKey,
    status: status,
    readAt: readAt,
    createdAt: createdAt,
  );
}

NotificationPreferences _preferences() {
  return const NotificationPreferences(
    locale: 'th',
    channels: NotificationChannels(inApp: true, push: true, line: false),
    digest: 'immediate',
    updatedAt: 'v1',
  );
}

Widget _shellHarness(List<NotificationDelivery> deliveries) {
  return ProviderScope(
    overrides: <Override>[
      authControllerProvider.overrideWith(AuthenticatedAuthController.new),
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
        () => StubNotificationDeliveriesController(deliveries),
      ),
    ],
    child: const LuckyCareApp(),
  );
}

void main() {
  group('K-T05 unread badge', () {
    testWidgets('reflects the unread count from the repository', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        _shellHarness(<NotificationDelivery>[
          _delivery(id: 'n1', templateKey: 'task.due_soon'),
          _delivery(id: 'n2', templateKey: 'medication.due'),
          _delivery(
            id: 'n3',
            templateKey: 'appt.reminder',
            readAt: '2026-10-24T09:00:00Z',
          ),
        ]),
      );
      await tester.pumpAndSettle();

      final Finder badge = find.byKey(const Key('notification-badge'));

      expect(badge, findsOneWidget);
      expect(
        find.descendant(of: badge, matching: find.text('2')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('notification-bell')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('notifications-page')), findsOneWidget);
      expect(find.byKey(const Key('notification-inbox-list')), findsOneWidget);
    });
  });

  group('K-T06 optimistic mark-read', () {
    test('updates immediately and rolls back when the call fails', () async {
      final _FakeNotificationsRepository repository =
          _FakeNotificationsRepository(
            deliveries: <NotificationDelivery>[
              _delivery(id: 'n1', templateKey: 'task.due_soon'),
            ],
            preferences: _preferences(),
          );
      repository.markReadGate = Completer<NotificationDelivery>();

      final ProviderContainer container = ProviderContainer.test(
        overrides: <Override>[
          notificationRepositoryProvider.overrideWithValue(repository),
        ],
      );
      final ProviderSubscription<AsyncValue<List<NotificationDelivery>>>
      subscription = container.listen(
        notificationDeliveriesControllerProvider,
        (_, _) {},
      );
      addTearDown(subscription.close);

      await container.read(notificationDeliveriesControllerProvider.future);
      final NotificationDelivery delivery = container
          .read(notificationDeliveriesControllerProvider)
          .value!
          .single;

      final Future<bool> pending = container
          .read(notificationDeliveriesControllerProvider.notifier)
          .markRead(delivery);
      await Future<void>.delayed(Duration.zero);

      expect(
        container.read(notificationDeliveriesControllerProvider).value!.single
            .isUnread,
        isFalse,
      );
      expect(container.read(unreadNotificationCountProvider), 0);

      repository.markReadGate!.completeError(
        const ApiFailure(
          status: 500,
          code: 'server_error',
          message: 'เกิดข้อผิดพลาดบางอย่าง',
        ),
      );

      expect(await pending, isFalse);
      expect(
        container.read(notificationDeliveriesControllerProvider).value!.single
            .isUnread,
        isTrue,
      );
      expect(container.read(unreadNotificationCountProvider), 1);
      expect(repository.markReadCalls, 1);
    });
  });

  group('K-T07 preference patch', () {
    test('sends only the channel field that changed', () async {
      final NotificationPreferences current = _preferences();
      final _FakeNotificationsRepository repository =
          _FakeNotificationsRepository(
            deliveries: const <NotificationDelivery>[],
            preferences: current,
          );
      final ProviderContainer container = ProviderContainer.test(
        overrides: <Override>[
          notificationRepositoryProvider.overrideWithValue(repository),
        ],
      );
      final ProviderSubscription<AsyncValue<NotificationPreferences>>
      subscription = container.listen(
        notificationPreferencesControllerProvider,
        (_, _) {},
      );
      addTearDown(subscription.close);

      await container.read(notificationPreferencesControllerProvider.future);

      final NotificationPreferences next = NotificationPreferences(
        locale: current.locale,
        channels: const NotificationChannels(
          inApp: true,
          push: false,
          line: false,
        ),
        quietHours: current.quietHours,
        digest: current.digest,
        updatedAt: current.updatedAt,
      );

      await container
          .read(notificationPreferencesControllerProvider.notifier)
          .save(next);

      expect(repository.lastPatchJson, <String, dynamic>{
        'channels': <String, bool>{'push': false},
      });
    });

    testWidgets('the settings form saves a single changed switch', (
      WidgetTester tester,
    ) async {
      final _FakeNotificationsRepository repository =
          _FakeNotificationsRepository(
            deliveries: const <NotificationDelivery>[],
            preferences: _preferences(),
          );

      await tester.pumpWidget(
        ProviderScope(
          overrides: <Override>[
            notificationRepositoryProvider.overrideWithValue(repository),
          ],
          child: MaterialApp(
            theme: buildAppTheme(),
            home: const NotificationsPage(),
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('notification-section-settings')));
      await tester.pumpAndSettle();

      expect(find.text('ช่องทางการแจ้งเตือน'), findsOneWidget);
      expect(find.text('ช่วงเวลางดรบกวน'), findsOneWidget);
      expect(find.text('การสรุปการแจ้งเตือน'), findsOneWidget);
      expect(find.text('ภาษา'), findsOneWidget);

      await tester.tap(find.byKey(const Key('pref-channel-push')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('pref-save')));
      await tester.pumpAndSettle();

      expect(repository.lastPatchJson, <String, dynamic>{
        'channels': <String, bool>{'push': false},
      });
    });
  });

  group('K-T08 failed delivery', () {
    testWidgets('shows a muted chip and the rest of the list still renders', (
      WidgetTester tester,
    ) async {
      final _FakeNotificationsRepository repository =
          _FakeNotificationsRepository(
            deliveries: <NotificationDelivery>[
              _delivery(
                id: 'nfailed',
                templateKey: 'task.due_soon',
                status: 'failed',
              ),
              _delivery(
                id: 'nsent',
                templateKey: 'appt.reminder',
                readAt: '2026-10-24T09:00:00Z',
              ),
            ],
            preferences: _preferences(),
          );

      await tester.pumpWidget(
        ProviderScope(
          overrides: <Override>[
            notificationRepositoryProvider.overrideWithValue(repository),
          ],
          child: MaterialApp(
            theme: buildAppTheme(),
            home: const NotificationsPage(),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('notification-inbox-list')), findsOneWidget);
      expect(
        find.byKey(const Key('notification-failed-nfailed')),
        findsOneWidget,
      );
      expect(find.text('ส่งไม่สำเร็จ'), findsOneWidget);
      expect(find.byKey(const Key('notification-nsent')), findsOneWidget);
      expect(find.text('มีการแจ้งเตือนนัดหมาย'), findsOneWidget);
    });
  });
}
