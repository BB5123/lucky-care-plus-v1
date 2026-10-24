import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profiles_repository.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart';

import 'support/tab_stubs.dart';
import 'support/test_doubles.dart';

Widget _harness(StubHealthController controller) {
  return ProviderScope(
    overrides: [healthControllerProvider.overrideWith(() => controller)],
    child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
  );
}

Widget _realHarness(Override override) {
  return ProviderScope(
    overrides: [override],
    child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
  );
}

WorkspaceContextRepository _workspaceRepository({required StubApiClient api}) {
  return WorkspaceContextRepository(
    FakeAuthRepository(),
    ProfilesRepository(api),
  );
}

void main() {
  group('D2-T02 health list', () {
    test('maps a measurement payload to a Thai label, value and unit', () {
      final HealthMeasurement measurement = HealthMeasurement.fromJson(
        <String, dynamic>{
          'id': 'measurement-1',
          'type': 'blood_pressure',
          'value': 150,
          'secondary_value': 95,
          'unit': 'mmHg',
          'recorded_at': '2026-10-24T09:05:00',
        },
      );

      expect(measurement.typeLabel, 'ความดันโลหิต');
      expect(measurement.valueLabel, '150/95');
      expect(measurement.unitLabel, 'mmHg');
      expect(measurement.recordedAtLabel, contains('24 ต.ค. 2569'));
      expect(measurement.recordedAtLabel, contains('09:05'));
    });

    testWidgets('renders newest first with unit and a Thai date', (
      WidgetTester tester,
    ) async {
      final HealthMeasurement older = HealthMeasurement(
        id: 'old',
        type: 'weight',
        value: 64.5,
        unit: 'kg',
        recordedAt: '2026-10-20T08:00:00',
      );
      final HealthMeasurement newer = HealthMeasurement.fromJson(
        <String, dynamic>{
          'id': 'new',
          'type': 'blood_pressure',
          'value': 120,
          'secondary_value': 80,
          'unit': 'mmHg',
          'recorded_at': '2026-10-24T09:05:00',
        },
      );

      await tester.pumpWidget(
        _harness(
          StubHealthController(
            HealthData(
              hasProfile: true,
              // Deliberately unsorted: HealthData imposes newest-first.
              measurements: <HealthMeasurement>[older, newer],
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('health-page')), findsOneWidget);
      expect(find.text('ความดันโลหิต'), findsOneWidget);
      expect(find.text('120/80'), findsOneWidget);
      expect(find.text('mmHg'), findsOneWidget);
      expect(find.textContaining('24 ต.ค. 2569'), findsOneWidget);
      expect(find.text('64.5'), findsOneWidget);
      expect(find.text('kg'), findsOneWidget);

      final double newerTop = tester
          .getTopLeft(find.byKey(const Key('measurement-new')))
          .dy;
      final double olderTop = tester
          .getTopLeft(find.byKey(const Key('measurement-old')))
          .dy;

      expect(newerTop, lessThan(olderTop));
    });
  });

  group('I2 profile list is the health tab source', () {
    testWidgets(
      'I2-T02 an empty profile list renders the no-profile state, not an error',
      (WidgetTester tester) async {
        final StubApiClient api = StubApiClient(
          response: <String, dynamic>{'data': <Object?>[]},
        );

        await tester.pumpWidget(
          _realHarness(
            workspaceContextRepositoryProvider.overrideWithValue(
              _workspaceRepository(api: api),
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(find.byKey(const Key('health-no-profile')), findsOneWidget);
        expect(find.byKey(const Key('health-empty-state')), findsNothing);
        expect(find.byKey(const Key('health-error')), findsNothing);
        expect(find.byKey(const Key('health-permission-state')), findsNothing);
        expect(api.requestedPaths, <String>[
          '/workspaces/workspace-1/profiles',
        ]);
      },
    );

    testWidgets(
      'I2-T03 a 403 shows the permission explanation without a retry',
      (WidgetTester tester) async {
        final StubApiClient api = StubApiClient(
          failure: const ApiFailure(
            status: 403,
            code: 'forbidden',
            message: 'คุณไม่มีสิทธิ์เข้าถึงรายการโปรไฟล์สุขภาพ',
          ),
        );

        await tester.pumpWidget(
          _realHarness(
            workspaceContextRepositoryProvider.overrideWithValue(
              _workspaceRepository(api: api),
            ),
          ),
        );
        await tester.pumpAndSettle();

        expect(
          find.byKey(const Key('health-permission-state')),
          findsOneWidget,
        );
        expect(find.byKey(const Key('health-error')), findsNothing);
        expect(find.byKey(const Key('state-retry-button')), findsNothing);
        expect(api.requestedPaths, <String>[
          '/workspaces/workspace-1/profiles',
        ]);
      },
    );
  });
}
