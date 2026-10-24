import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/ai/ai_repository.dart';
import 'package:lucky_care/core/medication/medication_models.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/ai_view.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart';
import 'package:lucky_care/features/health/medication_controller.dart';

import 'support/tab_stubs.dart';
import 'support/test_doubles.dart';

/// TASK M2 — mobile parity for the AI surfaces (summary + knowledge answers).
///
/// The fixtures below deliberately carry identifiers and a raw reading that the
/// API does return (record/document ids) or that the device already holds
/// (a measured value, the patient name). `M2-T07` asserts that none of them
/// reaches the AI panel (N-02).

const WorkspaceContext _context = WorkspaceContext(
  workspaceId: 'ws-1',
  currentProfile: HealthProfileSummary(
    id: 'profile-1',
    personId: 'person-1',
    displayName: 'คุณพ่อ',
    relationship: 'self',
    kind: 'self',
  ),
);

/// The server's `summary` envelope plus two record refs.
///
/// `value` / `secondary_value` are not part of the contract; they are in the
/// fixture so the test proves the client ignores anything it is not asked to
/// render.
const Map<String, dynamic> _summaryPayload = <String, dynamic>{
  'summary': 'ภาพรวม 30 วัน: ค่าที่บันทึกไว้ส่วนใหญ่อยู่ในเกณฑ์ที่ติดตามต่อเนื่องได้',
  'disclaimer': 'ข้อความนี้สร้างโดย AI จากบันทึกที่คุณบันทึกเอง ไม่ใช่การวินิจฉัย',
  'record_refs': <Map<String, dynamic>>[
    <String, dynamic>{
      'type': 'health_measurement',
      'id': 'measurement-8f3a2c',
      'value': 158,
      'secondary_value': 96,
    },
    <String, dynamic>{
      'type': 'health_measurement',
      'id': 'measurement-1b7d90',
    },
  ],
  'usage': <String, dynamic>{'requests_today': 1, 'remaining': 19},
  'model': 'test-model',
};

const Map<String, dynamic> _answerPayload = <String, dynamic>{
  'answer':
      'ควรฉีดวัคซีนไข้หวัดใหญ่ปีละครั้ง [A] และล้างมือบ่อย ๆ ด้วยสบู่ [B]',
  'sources': <Map<String, dynamic>>[
    <String, dynamic>{
      'label': 'A',
      'id': 'doc-ddc-13',
      'title': 'ไข้หวัดใหญ่ (Influenza)',
      'publisher': 'กรมควบคุมโรค กระทรวงสาธารณสุข',
      'url': 'https://ddc.moph.go.th/disease_detail.php?d=13',
      'published_at': '2026-05-01',
      'fetched_at': '2026-10-01',
      'review_status': 'reviewed',
    },
    <String, dynamic>{
      'label': 'B',
      'id': 'doc-anamai-4f',
      'title': 'การล้างมือ',
      'publisher': 'กรมอนามัย',
      'url': 'https://anamai.moph.go.th/hand-wash',
      'published_at': '',
      'fetched_at': '2026-09-15',
      'review_status': 'unreviewed',
    },
  ],
  'disclaimer': 'ข้อมูลนี้เป็นความรู้ทั่วไปจากเอกสารของหน่วยงานราชการ',
  'model': 'test-model',
  'usage': <String, dynamic>{'requests_today': 1, 'remaining': 19},
};

/// Uses the shared context but never touches `/me`: the tests supply the
/// workspace instead of a network round trip.
class _StubWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async => _context;
}

HealthMeasurement _bloodPressure() {
  return HealthMeasurement.fromJson(<String, dynamic>{
    'id': 'measurement-1',
    'type': 'blood_pressure',
    'value': 120,
    'secondary_value': 80,
    'unit': 'mmHg',
    'recorded_at': '2026-10-24T09:05:00',
  });
}

Widget _healthHarness(StubApiClient api) {
  return ProviderScope(
    overrides: <Override>[
      currentWorkspaceProvider.overrideWith(_StubWorkspaceController.new),
      healthControllerProvider.overrideWith(
        () => StubHealthController(
          HealthData(
            hasProfile: true,
            measurements: <HealthMeasurement>[_bloodPressure()],
          ),
        ),
      ),
      medicationControllerProvider.overrideWith(
        () => StubMedicationController(
          MedicationData(
            hasProfile: true,
            plans: const <MedicationPlan>[],
            adherence: const <MedicationAdherence>[],
          ),
        ),
      ),
      aiRepositoryProvider.overrideWithValue(AiRepository(api)),
    ],
    child: MaterialApp(theme: buildAppTheme(), home: const HealthPage()),
  );
}

Widget _aiHarness(StubApiClient api) {
  return ProviderScope(
    overrides: <Override>[
      currentWorkspaceProvider.overrideWith(_StubWorkspaceController.new),
      aiRepositoryProvider.overrideWithValue(AiRepository(api)),
    ],
    child: MaterialApp(
      theme: buildAppTheme(),
      home: const Scaffold(body: AiView()),
    ),
  );
}

/// One AI card plus its source list is taller than the default 800x600 test
/// surface; a phone-sized tall viewport keeps every assertion on-screen.
void _tallScreen(WidgetTester tester) {
  tester.view.physicalSize = const Size(1000, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

void main() {
  group('M2 AI parity', () {
    testWidgets('M2-T01 the third segment exists and the first two still work', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient();

      await tester.pumpWidget(_healthHarness(api));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('health-section-segments')), findsOneWidget);
      expect(find.text('ค่าวัด'), findsOneWidget);
      expect(find.text('ยา'), findsOneWidget);
      expect(find.text('ผู้ช่วย AI'), findsOneWidget);
      expect(find.text('120/80'), findsOneWidget);

      await tester.tap(find.byKey(const Key('health-section-medication')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('medication-empty-state')), findsOneWidget);
      expect(find.text('120/80'), findsNothing);

      await tester.tap(find.byKey(const Key('health-section-ai')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ai-view')), findsOneWidget);
      expect(find.byKey(const Key('ai-summary-card')), findsOneWidget);
      expect(find.byKey(const Key('knowledge-ask-card')), findsOneWidget);
      // Opening the tab sends nothing: the gateway is click-to-generate.
      expect(api.requestedPaths, isEmpty);

      await tester.tap(find.byKey(const Key('health-section-measurements')));
      await tester.pumpAndSettle();

      expect(find.text('120/80'), findsOneWidget);
      expect(find.byKey(const Key('ai-view')), findsNothing);
    });

    testWidgets('M2-T02 ai_disabled renders the explanatory card, not an error', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 503,
          code: 'ai_disabled',
          message: 'disabled',
        ),
      );

      await tester.pumpWidget(_healthHarness(api));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('health-section-ai')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('ai-summary-generate')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ai-unavailable-card')), findsOneWidget);
      expect(find.text('ผู้ช่วย AI ยังไม่เปิดใช้งาน'), findsOneWidget);
      expect(
        find.textContaining('ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้'),
        findsOneWidget,
      );
      // Both surfaces are replaced by that one card.
      expect(find.byKey(const Key('ai-summary-card')), findsNothing);
      expect(find.byKey(const Key('knowledge-ask-card')), findsNothing);
      // No error styling and no retry that cannot succeed.
      expect(find.byKey(const Key('ai-summary-error')), findsNothing);
      expect(find.byKey(const Key('knowledge-ask-error')), findsNothing);
      expect(find.byKey(const Key('state-retry-button')), findsNothing);

      // The manual paths are untouched.
      await tester.tap(find.byKey(const Key('health-section-measurements')));
      await tester.pumpAndSettle();

      expect(find.text('120/80'), findsOneWidget);
      expect(find.text('บันทึกค่า'), findsOneWidget);
    });

    testWidgets('M2-T03 a summary renders text, disclaimer and reference count', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _summaryPayload};

      await tester.pumpWidget(_aiHarness(api));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('ai-summary-generate')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ai-summary-text')), findsOneWidget);
      expect(find.textContaining('ค่าที่บันทึกไว้ส่วนใหญ่'), findsOneWidget);
      expect(find.byKey(const Key('ai-summary-disclaimer')), findsOneWidget);
      expect(
        find.textContaining('ไม่ใช่การวินิจฉัย'),
        findsWidgets,
      );
      expect(find.textContaining('อ้างอิง 2 รายการ'), findsOneWidget);

      // The expander reveals a count only.
      await tester.tap(find.byKey(const Key('ai-summary-refs')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ai-summary-refs-detail')), findsOneWidget);
      expect(find.textContaining('สรุปนี้สร้างจากบันทึก 2 รายการ'), findsOneWidget);
      expect(find.textContaining('measurement-8f3a2c'), findsNothing);

      // Regenerate action: the same button now offers a new draft.
      expect(find.text('สร้างสรุปใหม่'), findsOneWidget);
    });

    testWidgets('M2-T04 an answer renders [A] markers and a dated source list', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _answerPayload};

      await tester.pumpWidget(_aiHarness(api));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'ไข้หวัดใหญ่ป้องกันอย่างไร',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('knowledge-answer')), findsOneWidget);
      expect(find.textContaining('[A]'), findsWidgets);
      expect(find.textContaining('[B]'), findsWidgets);
      expect(find.byKey(const Key('knowledge-disclaimer')), findsOneWidget);

      expect(
        find.textContaining('กรมควบคุมโรค กระทรวงสาธารณสุข'),
        findsOneWidget,
      );
      expect(find.textContaining('1 พ.ค. 2569'), findsOneWidget);
      expect(find.textContaining('กรมอนามัย'), findsOneWidget);
      expect(find.textContaining('15 ก.ย. 2569'), findsOneWidget);

      expect(api.lastPostData, <String, dynamic>{
        'question': 'ไข้หวัดใหญ่ป้องกันอย่างไร',
      });
    });

    testWidgets('M2-T05 an unreviewed source shows the review hint', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _answerPayload};

      await tester.pumpWidget(_aiHarness(api));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'ล้างมืออย่างไร',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      expect(find.text('ยังไม่ผ่านการตรวจ'), findsOneWidget);
      expect(
        find.byKey(const Key('knowledge-source-unreviewed-B')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('knowledge-source-unreviewed-A')),
        findsNothing,
      );
    });

    testWidgets('M2-T06 429 shows the quota line and disables the input', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 429,
          code: 'ai_quota_exceeded',
          message: 'quota',
        ),
      );

      await tester.pumpWidget(_aiHarness(api));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'ไข้หวัดใหญ่ป้องกันอย่างไร',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('knowledge-quota')), findsOneWidget);
      expect(
        find.textContaining('ใช้ได้อีกครั้งพรุ่งนี้'),
        findsOneWidget,
      );
      // Muted, not an error: no banner, no retry, no danger sentence.
      expect(find.byKey(const Key('knowledge-ask-error')), findsNothing);
      expect(find.byKey(const Key('state-retry-button')), findsNothing);

      final TextField field = tester.widget<TextField>(
        find.byKey(const Key('knowledge-question')),
      );
      expect(field.enabled, isFalse);

      final FilledButton send = tester.widget<FilledButton>(
        find.byKey(const Key('knowledge-ask-submit')),
      );
      expect(send.onPressed, isNull);
    });

    testWidgets('M2-T07 the panel renders no document id and no raw value', (
      WidgetTester tester,
    ) async {
      _tallScreen(tester);
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _summaryPayload};

      await tester.pumpWidget(_aiHarness(api));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('ai-summary-generate')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('ai-summary-refs')));
      await tester.pumpAndSettle();

      api.postResponse = <String, dynamic>{'data': _answerPayload};
      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'ไข้หวัดใหญ่ป้องกันอย่างไร',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      // Sanity: both surfaces rendered something.
      expect(find.byKey(const Key('ai-summary-text')), findsOneWidget);
      expect(find.byKey(const Key('knowledge-answer')), findsOneWidget);

      const List<String> forbidden = <String>[
        'measurement-8f3a2c',
        'measurement-1b7d90',
        'doc-ddc-13',
        'doc-anamai-4f',
        '158/96',
        '158',
        'profile-1',
        'คุณพ่อ',
      ];

      for (final String needle in forbidden) {
        expect(
          find.textContaining(needle),
          findsNothing,
          reason: 'the AI panel must not render "$needle"',
        );
      }
    });
  });
}
