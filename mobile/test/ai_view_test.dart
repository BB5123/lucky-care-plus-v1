import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/ai/ai_repository.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/widgets/ai_summary_card.dart';
import 'package:lucky_care/features/health/widgets/knowledge_ask_card.dart';

import 'support/test_doubles.dart';

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

const Map<String, dynamic> _summaryPayload = <String, dynamic>{
  'summary': 'สรุปแนวโน้ม: ความดันสูงขึ้นเล็กน้อย',
  'disclaimer': 'คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง',
  'record_refs': <Map<String, String>>[
    <String, String>{'type': 'health_measurement', 'id': 'measurement-1'},
  ],
  'usage': <String, dynamic>{'requests_today': 1, 'remaining': 19},
  'model': 'test-model',
};

const Map<String, dynamic> _answerPayload = <String, dynamic>{
  'answer': 'ควรฉีดวัคซีนไข้หวัดใหญ่ประจำปี [A]',
  'sources': <Map<String, dynamic>>[
    <String, dynamic>{
      'label': 'A',
      'id': 'ddc-disease-13',
      'title': 'ไข้หวัดใหญ่ (Influenza)',
      'publisher': 'กรมควบคุมโรค กระทรวงสาธารณสุข',
      'url': 'https://ddc.moph.go.th/disease_detail.php?d=13',
    },
  ],
  'disclaimer': 'ข้อมูลนี้เป็นความรู้ทั่วไปจากเอกสารของหน่วยงานราชการ',
  'model': 'test-model',
  'usage': <String, dynamic>{'requests_today': 1, 'remaining': 19},
};

Widget _harness(Widget child, StubApiClient api) {
  return ProviderScope(
    overrides: <Override>[
      aiRepositoryProvider.overrideWithValue(AiRepository(api)),
    ],
    child: MaterialApp(
      theme: buildAppTheme(),
      home: Scaffold(body: SingleChildScrollView(child: child)),
    ),
  );
}

void main() {
  group('AI summary card', () {
    testWidgets('G4AI-T05 renders the summary and disclaimer after a click', (
      WidgetTester tester,
    ) async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _summaryPayload};

      await tester.pumpWidget(
        _harness(const AiSummaryCard(context: _context), api),
      );

      await tester.tap(find.byKey(const Key('ai-summary-generate')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ai-summary-text')), findsOneWidget);
      expect(find.textContaining('ความดันสูงขึ้นเล็กน้อย'), findsOneWidget);
      expect(
        find.textContaining('ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง'),
        findsOneWidget,
      );
      expect(find.textContaining('อ้างอิง 1 รายการ'), findsOneWidget);
    });

    testWidgets('G4AI-T06 maps ai_disabled to a Thai message', (
      WidgetTester tester,
    ) async {
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 503,
          code: 'ai_disabled',
          message: 'disabled',
        ),
      );

      await tester.pumpWidget(
        _harness(const AiSummaryCard(context: _context), api),
      );

      await tester.tap(find.byKey(const Key('ai-summary-generate')));
      await tester.pumpAndSettle();

      expect(find.textContaining('ฟีเจอร์ AI ยังไม่เปิดใช้งาน'), findsOneWidget);
    });
  });

  group('Knowledge ask card', () {
    testWidgets('G4AI-T07 renders the cited answer and source', (
      WidgetTester tester,
    ) async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _answerPayload};

      await tester.pumpWidget(
        _harness(const KnowledgeAskCard(context: _context), api),
      );

      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'ไข้หวัดใหญ่ป้องกันอย่างไร',
      );
      await tester.pump();

      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      expect(find.textContaining('[A]'), findsWidgets);
      expect(find.textContaining('ไข้หวัดใหญ่ (Influenza)'), findsWidgets);
      expect(
        find.textContaining('ความรู้ทั่วไปจากเอกสารของหน่วยงานราชการ'),
        findsOneWidget,
      );
      expect(api.lastPostData, <String, dynamic>{
        'question': 'ไข้หวัดใหญ่ป้องกันอย่างไร',
      });
    });

    testWidgets('G4AI-T08 maps knowledge_not_found to a Thai message', (
      WidgetTester tester,
    ) async {
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 422,
          code: 'knowledge_not_found',
          message: 'not found',
        ),
      );

      await tester.pumpWidget(
        _harness(const KnowledgeAskCard(context: _context), api),
      );

      await tester.enterText(
        find.byKey(const Key('knowledge-question')),
        'คำถามที่ไม่มีในฐานความรู้',
      );
      await tester.pump();
      await tester.tap(find.byKey(const Key('knowledge-ask-submit')));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('ไม่พบข้อมูลในฐานความรู้สำหรับคำถามนี้'),
        findsOneWidget,
      );
    });
  });
}
