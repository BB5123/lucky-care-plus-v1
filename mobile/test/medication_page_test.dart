import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/medication/medication_models.dart';
import 'package:lucky_care/core/medication/medication_repository.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart';
import 'package:lucky_care/features/health/medication_controller.dart';
import 'package:lucky_care/features/health/widgets/adherence_card.dart';
import 'package:lucky_care/features/health/widgets/record_dose_sheet.dart';

import 'support/tab_stubs.dart';

class _FixedWorkspaceController extends CurrentWorkspaceController {
  _FixedWorkspaceController(this.context);

  final WorkspaceContext context;

  @override
  Future<WorkspaceContext> build() async => context;
}

class _RecordingMedicationRepository implements MedicationRepository {
  _RecordingMedicationRepository({this.plans = const <MedicationPlan>[]});

  final List<MedicationPlan> plans;
  int recordCalls = 0;

  @override
  Future<List<MedicationPlan>> listPlans(WorkspaceContext context) async {
    return plans;
  }

  @override
  Future<List<MedicationAdherence>> adherence(
    WorkspaceContext context, {
    int days = 7,
  }) async {
    return const <MedicationAdherence>[];
  }

  @override
  Future<MedicationRecord> recordDose(
    WorkspaceContext context, {
    required String planId,
    required String status,
    String? note,
    String? scheduledFor,
  }) async {
    recordCalls += 1;

    return MedicationRecord(
      id: 'record-1',
      planId: planId,
      status: status,
      note: note,
      scheduledFor: scheduledFor,
    );
  }
}

MedicationPlan _activePlan() {
  return const MedicationPlan(
    id: 'plan-active',
    name: 'Metformin',
    dosage: '500 mg',
    dosesPerDay: 2,
    scheduleTimes: <String>['08:00', '20:00'],
    startsOn: '2026-10-20',
    status: 'active',
  );
}

MedicationPlan _discontinuedPlan() {
  return const MedicationPlan(
    id: 'plan-old',
    name: 'Amlodipine',
    dosage: '5 mg',
    dosesPerDay: 1,
    scheduleTimes: <String>['08:00'],
    startsOn: '2026-09-01',
    endsOn: '2026-10-01',
    status: 'discontinued',
    discontinuedReason: 'แพทย์สั่งหยุดยา',
  );
}

Widget _healthHarness(MedicationData medication) {
  return ProviderScope(
    overrides: <Override>[
      healthControllerProvider.overrideWith(
        () => StubHealthController(
          HealthData(
            hasProfile: true,
            measurements: const <HealthMeasurement>[],
          ),
        ),
      ),
      medicationControllerProvider.overrideWith(
        () => StubMedicationController(medication),
      ),
    ],
    child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
  );
}

WorkspaceContext _workspaceContext() {
  return const WorkspaceContext(
    workspaceId: 'workspace-1',
    currentProfile: HealthProfileSummary(
      id: 'profile-1',
      personId: 'person-1',
      displayName: 'สมชาย',
      relationship: 'self',
      kind: 'self',
    ),
  );
}

void main() {
  group('K-T01 health segments', () {
    testWidgets('shows measurements and medication, defaulting to measurements', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        _healthHarness(
          MedicationData(
            hasProfile: true,
            plans: <MedicationPlan>[_activePlan()],
            adherence: const <MedicationAdherence>[],
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('ค่าวัด'), findsOneWidget);
      expect(find.text('ยา'), findsOneWidget);
      expect(find.byKey(const Key('health-empty-state')), findsOneWidget);
      expect(find.byKey(const Key('medication-list')), findsNothing);

      await tester.tap(find.byKey(const Key('health-section-medication')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('medication-list')), findsOneWidget);
      expect(find.byKey(const Key('health-empty-state')), findsNothing);
    });
  });

  group('K-T02 plan cards', () {
    testWidgets('shows dosage, times, status and no action for discontinued', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        _healthHarness(
          MedicationData(
            hasProfile: true,
            plans: <MedicationPlan>[_activePlan(), _discontinuedPlan()],
            adherence: const <MedicationAdherence>[],
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('health-section-medication')));
      await tester.pumpAndSettle();

      expect(find.text('Metformin'), findsOneWidget);
      expect(find.text('500 mg'), findsOneWidget);
      expect(find.text('วันละ 2 ครั้ง'), findsOneWidget);
      expect(find.text('08:00 · 20:00'), findsOneWidget);
      expect(find.text('กำลังใช้'), findsOneWidget);
      expect(
        find.byKey(const Key('medication-record-plan-active')),
        findsOneWidget,
      );

      expect(find.text('Amlodipine'), findsOneWidget);
      expect(find.text('หยุดใช้แล้ว'), findsOneWidget);
      expect(find.textContaining('แพทย์สั่งหยุดยา'), findsOneWidget);
      expect(
        find.byKey(const Key('medication-record-plan-old')),
        findsNothing,
      );
    });
  });

  group('K-T03 skipped dose', () {
    testWidgets('without a note is blocked before the repository is called', (
      WidgetTester tester,
    ) async {
      final _RecordingMedicationRepository repository =
          _RecordingMedicationRepository(
            plans: <MedicationPlan>[_activePlan()],
          );

      await tester.pumpWidget(
        ProviderScope(
          overrides: <Override>[
            currentWorkspaceProvider.overrideWith(
              () => _FixedWorkspaceController(_workspaceContext()),
            ),
            medicationRepositoryProvider.overrideWithValue(repository),
          ],
          child: MaterialApp(
            theme: buildAppTheme(),
            home: Scaffold(body: RecordDoseSheet(plan: _activePlan())),
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('dose-status-skipped')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('dose-save')));
      await tester.pumpAndSettle();

      expect(repository.recordCalls, 0);
      expect(find.text('กรุณาระบุเหตุผลที่ข้ามยา'), findsOneWidget);
    });
  });

  group('K-T04 adherence card', () {
    testWidgets('renders the server percentages exactly as received', (
      WidgetTester tester,
    ) async {
      final MedicationAdherence adherence = MedicationAdherence.fromJson(
        <String, dynamic>{
          'plan_id': 'plan-active',
          'name': 'Metformin',
          'expected': 6,
          'taken': 4,
          'skipped': 0,
          'missed': 0,
          'percent': 66.7,
          'daily': <Object?>[
            <String, dynamic>{
              'date': '2026-10-18',
              'expected': 2,
              'taken': 2,
              'skipped': 0,
              'missed': 0,
              'percent': 100.0,
            },
            <String, dynamic>{
              'date': '2026-10-19',
              'expected': 2,
              'taken': 1,
              'skipped': 0,
              'missed': 0,
              'percent': 50.0,
            },
            <String, dynamic>{
              'date': '2026-10-20',
              'expected': 2,
              'taken': 1,
              'skipped': 0,
              'missed': 0,
              'percent': 50.0,
            },
          ],
        },
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: buildAppTheme(),
          home: Scaffold(body: AdherenceCard(adherence: adherence)),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('66.7%'), findsOneWidget);
      expect(
        find.byKey(const Key('adherence-expected-plan-active')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('adherence-taken-plan-active')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('adherence-skipped-plan-active')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('adherence-missed-plan-active')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('adherence-expected-plan-active')),
          matching: find.text('6'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('adherence-taken-plan-active')),
          matching: find.text('4'),
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('adherence-bar-plan-active-2026-10-18')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('adherence-bar-plan-active-2026-10-20')),
        findsOneWidget,
      );
    });
  });
}
