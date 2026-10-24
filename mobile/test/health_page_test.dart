import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart';

import 'support/tab_stubs.dart';

Widget _harness(StubHealthController controller) {
  return ProviderScope(
    overrides: [healthControllerProvider.overrideWith(() => controller)],
    child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
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
}
