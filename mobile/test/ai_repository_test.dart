import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/ai/ai_repository.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';

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

void main() {
  group('AI repository', () {
    test(
      'G4AI-T01 posts to the profile ai/summaries path and parses it',
      () async {
        final StubApiClient api = StubApiClient()
          ..postResponse = <String, dynamic>{'data': _summaryPayload};
        final AiRepository repository = AiRepository(api);

        final dynamic summary = await repository.summarize(_context);

        expect(
          api.requestedPaths.last,
          '/workspaces/ws-1/profiles/profile-1/ai/summaries',
        );
        expect(api.lastPostData, isEmpty);
        expect(summary.summary, contains('สรุปแนวโน้ม'));
        expect(summary.recordRefs, 1);
        expect(summary.remaining, 19);
        expect(summary.model, 'test-model');
      },
    );

    test('G4AI-T02 posts the trimmed question to knowledge-answers', () async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{'data': _answerPayload};
      final AiRepository repository = AiRepository(api);

      final dynamic answer = await repository.ask(
        _context,
        '  ไข้หวัดใหญ่ป้องกันอย่างไร  ',
      );

      expect(
        api.requestedPaths.last,
        '/workspaces/ws-1/profiles/profile-1/ai/knowledge-answers',
      );
      expect(api.lastPostData, <String, dynamic>{
        'question': 'ไข้หวัดใหญ่ป้องกันอย่างไร',
      });
      expect(answer.answer, contains('[A]'));
      expect(answer.sources.length, 1);
      expect(answer.sources.first.title, 'ไข้หวัดใหญ่ (Influenza)');
      expect(answer.remaining, 19);
    });

    test(
      'G4AI-T03 rejects a malformed envelope with the protocol failure',
      () async {
        final StubApiClient api = StubApiClient()
          ..postResponse = <String, dynamic>{'data': <String, dynamic>{}};
        final AiRepository repository = AiRepository(api);

        await expectLater(
          repository.summarize(_context),
          throwsA(
            isA<ApiFailure>().having(
              (ApiFailure failure) => failure.code,
              'code',
              'protocol',
            ),
          ),
        );
      },
    );

    test('G4AI-T04 refuses to call the API without a profile', () async {
      final StubApiClient api = StubApiClient();
      final AiRepository repository = AiRepository(api);

      await expectLater(
        repository.summarize(const WorkspaceContext(workspaceId: 'ws-1')),
        throwsA(
          isA<ApiFailure>().having(
            (ApiFailure failure) => failure.code,
            'code',
            'out_of_scope',
          ),
        ),
      );
      expect(api.requestedPaths, isEmpty);
    });
  });
}
