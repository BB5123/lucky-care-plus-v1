import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/widgets/record_measurement_sheet.dart';

class _RecordingHealthController extends HealthController {
  _RecordingHealthController({this.failure});

  final ApiFailure? failure;
  int calls = 0;

  @override
  Future<HealthData> build() async {
    return HealthData(
      hasProfile: true,
      measurements: const <HealthMeasurement>[],
    );
  }

  @override
  Future<void> record(CreateMeasurementInput input) async {
    calls++;

    final ApiFailure? failure = this.failure;

    if (failure != null) {
      throw failure;
    }
  }
}

Future<_RecordingHealthController> _pumpSheet(
  WidgetTester tester, {
  ApiFailure? failure,
}) async {
  final _RecordingHealthController controller = _RecordingHealthController(
    failure: failure,
  );

  await tester.pumpWidget(
    ProviderScope(
      overrides: [healthControllerProvider.overrideWith(() => controller)],
      child: MaterialApp(
        theme: buildAppTheme(),
        home: const Scaffold(body: RecordMeasurementSheet()),
      ),
    ),
  );
  await tester.pumpAndSettle();

  return controller;
}

Future<void> _tapSave(WidgetTester tester) async {
  final Finder save = find.byKey(const Key('health-save'));

  await tester.ensureVisible(save);
  await tester.tap(save);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
    'D2-T03 the BP form requires diastolic and rejects systolic <= diastolic',
    (WidgetTester tester) async {
      final _RecordingHealthController controller = await _pumpSheet(tester);

      await tester.enterText(
        find.byKey(const Key('health-bp-systolic')),
        '120',
      );
      await _tapSave(tester);

      expect(find.text('กรุณากรอกค่าความดันตัวล่าง'), findsOneWidget);
      expect(controller.calls, 0);

      await tester.enterText(
        find.byKey(const Key('health-bp-diastolic')),
        '130',
      );
      await _tapSave(tester);

      expect(find.text('ค่าความดันตัวบนต้องมากกว่าค่าตัวล่าง'), findsOneWidget);
      expect(controller.calls, 0);
    },
  );

  testWidgets('D2-T04 a 422 renders inline under the field the server names', (
    WidgetTester tester,
  ) async {
    final _RecordingHealthController controller = await _pumpSheet(
      tester,
      failure: const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'ข้อมูลไม่ถูกต้อง',
        fieldErrors: <String, String>{
          'secondary_value': 'ค่าความดันตัวล่างไม่ถูกต้อง',
        },
      ),
    );

    await tester.enterText(find.byKey(const Key('health-bp-systolic')), '120');
    await tester.enterText(find.byKey(const Key('health-bp-diastolic')), '80');
    await _tapSave(tester);

    expect(controller.calls, 1);

    final Finder message = find.text('ค่าความดันตัวล่างไม่ถูกต้อง');

    expect(message, findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('health-bp-diastolic')),
        matching: message,
      ),
      findsOneWidget,
    );
  });
}
