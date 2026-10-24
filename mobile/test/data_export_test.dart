import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/export/data_export_controller.dart';
import 'package:lucky_care/core/export/data_export_models.dart';
import 'package:lucky_care/core/export/data_export_repository.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/family/family_controller.dart';
import 'package:lucky_care/features/family/family_models.dart';
import 'package:lucky_care/features/family/family_page.dart';
import 'package:lucky_care/features/family/widgets/data_export_card.dart';

import 'support/test_doubles.dart';

const String _expiry = '2026-10-30T03:00:00Z';
const String _exportUrl = 'https://files.example.test/export-1.json?token=abc';

const HealthProfileSummary _profile = HealthProfileSummary(
  id: 'profile-1',
  personId: 'person-1',
  displayName: 'สมชาย ใจดี',
  relationship: 'self',
  kind: 'self',
);

const WorkspaceContext _workspace = WorkspaceContext(
  workspaceId: 'ws-1',
  workspaceName: 'บ้านใจดี',
  role: 'profile_owner',
  profiles: <HealthProfileSummary>[_profile],
  currentProfile: _profile,
);

class _FixedWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async => _workspace;
}

class _FamilyWithProfileController extends FamilyController {
  @override
  Future<FamilyData> build() async {
    return FamilyData(
      hasWorkspace: true,
      profileId: 'profile-1',
      members: const <FamilyMembership>[],
    );
  }
}

class _FamilyWithoutMemberListController extends FamilyController {
  @override
  Future<FamilyData> build() async {
    return FamilyData(
      hasWorkspace: true,
      canReadMembers: false,
      profileId: 'profile-1',
      members: const <FamilyMembership>[],
    );
  }
}

/// In-memory [DataExportRepository] that records every call. It answers with
/// the same typed failures the real repository produces from an `ApiFailure`.
class _FakeExportRepository implements DataExportRepository {
  ExportRequestResult requestResult = const ExportRequestResult(
    id: 'export-1',
    status: 'queued',
  );

  /// Consumed one per status call; the last entry repeats.
  List<ExportSnapshot> statusResults = <ExportSnapshot>[];

  ExportFailure? requestFailure;
  ExportFailure? statusFailure;
  ExportFailure? linkFailure;

  ExportLink linkResult = const ExportLink(url: _exportUrl, expiresAt: _expiry);

  int requestCalls = 0;
  int statusCalls = 0;
  int linkCalls = 0;

  String? lastProfileId;
  String? lastStatusId;
  String? lastLinkId;

  @override
  Future<ExportRequestResult> requestExport(
    WorkspaceContext context,
    String profileId,
  ) async {
    requestCalls++;
    lastProfileId = profileId;

    final ExportFailure? failure = requestFailure;

    if (failure != null) {
      throw failure;
    }

    return requestResult;
  }

  @override
  Future<ExportSnapshot> exportStatus(
    WorkspaceContext context,
    String profileId,
    String id,
  ) async {
    statusCalls++;
    lastStatusId = id;

    final ExportFailure? failure = statusFailure;

    if (failure != null) {
      throw failure;
    }

    if (statusResults.length > 1) {
      return statusResults.removeAt(0);
    }

    if (statusResults.isNotEmpty) {
      return statusResults.first;
    }

    return ExportSnapshot(id: id, status: 'queued');
  }

  @override
  Future<ExportLink> exportLink(
    WorkspaceContext context,
    String profileId,
    String id,
  ) async {
    linkCalls++;
    lastLinkId = id;

    final ExportFailure? failure = linkFailure;

    if (failure != null) {
      throw failure;
    }

    return linkResult;
  }
}

Widget _harness(_FakeExportRepository repository) {
  return ProviderScope(
    overrides: [
      dataExportRepositoryProvider.overrideWithValue(repository),
      currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
    ],
    child: MaterialApp(
      theme: buildAppTheme(),
      home: const Scaffold(
        body: SingleChildScrollView(
          child: Padding(
            padding: EdgeInsets.all(16),
            child: DataExportCard(profileId: 'profile-1'),
          ),
        ),
      ),
    ),
  );
}

Future<void> _tap(WidgetTester tester, Key key) async {
  final Finder target = find.byKey(key);

  await tester.ensureVisible(target);
  await tester.tap(target);
  await tester.pumpAndSettle();
}

/// Advances the four-second polling timer and lets the fake future complete.
Future<void> _advancePoll(WidgetTester tester) async {
  await tester.pump(dataExportPollInterval);
  await tester.pumpAndSettle();
}

List<MethodCall> _capturePlatformCalls(WidgetTester tester) {
  final List<MethodCall> calls = <MethodCall>[];

  tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
    SystemChannels.platform,
    (MethodCall call) async {
      calls.add(call);

      return null;
    },
  );

  addTearDown(() {
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      null,
    );
  });

  return calls;
}

void main() {
  testWidgets('EXM-T01 renders the explanation and the request action', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(_harness(_FakeExportRepository()));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('data-export-card')), findsOneWidget);
    expect(find.text('ขอสำเนาข้อมูลของฉัน'), findsOneWidget);
    expect(find.byKey(const Key('export-explanation')), findsOneWidget);
    expect(find.textContaining('ไม่รวมรูปภาพเอกสาร'), findsOneWidget);
    expect(find.textContaining('ข้อมูลเมตาของเอกสาร'), findsOneWidget);
    expect(find.byKey(const Key('export-request')), findsOneWidget);
    expect(find.text('ขอข้อมูล'), findsOneWidget);
    expect(find.byKey(const Key('export-status')), findsNothing);
  });

  testWidgets('the family page mounts the card for the current profile', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          familyControllerProvider.overrideWith(
            _FamilyWithProfileController.new,
          ),
          currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
          dataExportRepositoryProvider.overrideWithValue(
            _FakeExportRepository(),
          ),
        ],
        child: MaterialApp(theme: buildAppTheme(), home: const FamilyPage()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('family-list')), findsOneWidget);
    expect(find.byKey(const Key('data-export-card')), findsOneWidget);
    expect(find.text('ขอสำเนาข้อมูลของฉัน'), findsOneWidget);
  });

  testWidgets('the card still renders when the member list is denied', (
    WidgetTester tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          familyControllerProvider.overrideWith(
            _FamilyWithoutMemberListController.new,
          ),
          currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
          dataExportRepositoryProvider.overrideWithValue(
            _FakeExportRepository(),
          ),
        ],
        child: MaterialApp(theme: buildAppTheme(), home: const FamilyPage()),
      ),
    );
    await tester.pumpAndSettle();

    // The denial is explained, but it does not hide the independent export
    // action or turn the tab into an error banner.
    expect(find.byKey(const Key('family-permission-state')), findsOneWidget);
    expect(find.byKey(const Key('data-export-card')), findsOneWidget);
    expect(find.byKey(const Key('export-request')), findsOneWidget);
    expect(find.byKey(const Key('family-error')), findsNothing);
  });

  testWidgets('EXM-T02 requesting shows the queued state and one request', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository();

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(repository.requestCalls, 1);
    expect(repository.lastProfileId, 'profile-1');
    expect(find.byKey(const Key('export-status')), findsOneWidget);
    expect(
      find.text('คำขอของคุณอยู่ในคิว กำลังจัดเตรียมสำเนาข้อมูล...'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('export-refresh')), findsOneWidget);

    // Finish the job so no polling timer outlives the test.
    repository.statusResults = <ExportSnapshot>[
      const ExportSnapshot(id: 'export-1', status: 'ready', byteSize: 512),
    ];

    await _advancePoll(tester);

    expect(repository.requestCalls, 1);
    expect(find.byKey(const Key('export-status')), findsNothing);
  });

  testWidgets('EXM-T03 polling stops at ready and renders size and expiry', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository()
      ..statusResults = <ExportSnapshot>[
        const ExportSnapshot(id: 'export-1', status: 'building'),
        const ExportSnapshot(
          id: 'export-1',
          status: 'ready',
          expiresAt: _expiry,
          byteSize: 1536,
        ),
      ];

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(
      find.text('คำขอของคุณอยู่ในคิว กำลังจัดเตรียมสำเนาข้อมูล...'),
      findsOneWidget,
    );

    await _advancePoll(tester);

    expect(repository.statusCalls, 1);
    expect(find.text('กำลังจัดเตรียมสำเนาข้อมูล...'), findsOneWidget);

    await _advancePoll(tester);

    expect(repository.statusCalls, 2);
    expect(find.byKey(const Key('export-size')), findsOneWidget);
    expect(find.text('1.5 KB'), findsOneWidget);
    expect(find.byKey(const Key('export-expiry')), findsOneWidget);
    expect(find.textContaining('30 ต.ค. 2569'), findsOneWidget);

    // The timer stopped at ready: more time must not produce another call.
    await tester.pump(const Duration(seconds: 12));
    await tester.pumpAndSettle();

    expect(repository.statusCalls, 2);
  });

  testWidgets('EXM-T04 download fetches the link and the copy path uses it', (
    WidgetTester tester,
  ) async {
    final List<MethodCall> calls = _capturePlatformCalls(tester);
    final _FakeExportRepository repository = _FakeExportRepository()
      ..requestResult = const ExportRequestResult(
        id: 'export-1',
        status: 'ready',
      );

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(find.byKey(const Key('export-download')), findsOneWidget);

    await _tap(tester, const Key('export-download'));

    expect(repository.linkCalls, 1);
    expect(repository.lastLinkId, 'export-1');
    expect(find.byKey(const Key('export-url')), findsOneWidget);
    expect(find.text(_exportUrl), findsOneWidget);
    expect(find.byKey(const Key('export-copy')), findsOneWidget);

    await _tap(tester, const Key('export-copy'));

    expect(find.byKey(const Key('export-copied')), findsOneWidget);

    final List<MethodCall> writes = calls
        .where((MethodCall call) => call.method == 'Clipboard.setData')
        .toList(growable: false);

    expect(writes, isNotEmpty);

    final Map<Object?, Object?> arguments =
        writes.last.arguments as Map<Object?, Object?>;

    expect(arguments['text'], _exportUrl);
  });

  testWidgets('EXM-T05 429 shows the wait line and disables the action', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository()
      ..requestFailure = const ExportRateLimited(retryAfterSeconds: 5400);

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(repository.requestCalls, 1);
    expect(find.byKey(const Key('export-rate-limit')), findsOneWidget);
    expect(
      find.textContaining('สามารถขอข้อมูลใหม่ได้ในอีก 1 ชั่วโมง 30 นาที'),
      findsOneWidget,
    );

    final FilledButton button = tester.widget<FilledButton>(
      find.byKey(const Key('export-request')),
    );

    expect(button.onPressed, isNull);
  });

  testWidgets('EXM-T06 403 renders the calm explanation, not an error banner', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository()
      ..requestFailure = const ExportNotPermitted();

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(find.byKey(const Key('export-permission')), findsOneWidget);
    expect(find.text('สิทธิ์ของคุณยังไม่รวมการขอข้อมูล'), findsOneWidget);
    expect(find.textContaining('ติดต่อเจ้าของครอบครัว'), findsOneWidget);
    expect(find.byKey(const Key('export-error')), findsNothing);
    expect(find.byKey(const Key('export-request')), findsNothing);
    expect(find.text('ลองอีกครั้ง'), findsNothing);
  });

  testWidgets('EXM-T07 410 swaps the ready state back to ขอข้อมูลใหม่', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository()
      ..requestResult = const ExportRequestResult(
        id: 'export-1',
        status: 'ready',
      )
      ..linkFailure = const ExportExpired();

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));

    expect(find.byKey(const Key('export-download')), findsOneWidget);

    await _tap(tester, const Key('export-download'));

    expect(repository.linkCalls, 1);
    expect(find.byKey(const Key('export-download')), findsNothing);
    expect(find.byKey(const Key('export-url')), findsNothing);
    expect(find.byKey(const Key('export-expired')), findsOneWidget);
    expect(find.text('ขอข้อมูลใหม่'), findsOneWidget);

    // The renewed request is accepted; nothing from the expired job remains.
    await _tap(tester, const Key('export-request'));

    expect(repository.requestCalls, 2);
  });

  testWidgets('EXM-T08 renders no id, object key or health value', (
    WidgetTester tester,
  ) async {
    final _FakeExportRepository repository = _FakeExportRepository()
      ..statusResults = <ExportSnapshot>[
        const ExportSnapshot(
          id: 'export-1',
          status: 'ready',
          expiresAt: _expiry,
          byteSize: 2048,
        ),
      ];

    await tester.pumpWidget(_harness(repository));
    await tester.pumpAndSettle();

    await _tap(tester, const Key('export-request'));
    await _advancePoll(tester);

    // The card only exposes lifecycle metadata.
    expect(find.byKey(const Key('export-size')), findsOneWidget);
    expect(find.text('2 KB'), findsOneWidget);
    expect(find.byKey(const Key('export-expiry')), findsOneWidget);

    expect(find.textContaining('object_key'), findsNothing);
    expect(find.textContaining('document-123'), findsNothing);
    expect(find.textContaining('120/80'), findsNothing);
    expect(find.textContaining('health_value'), findsNothing);
  });

  group('DataExportRepository', () {
    test('posts the request to the profile export path', () async {
      final StubApiClient api = StubApiClient()
        ..postResponse = <String, dynamic>{
          'data': <String, dynamic>{'id': 'export-1', 'status': 'queued'},
        };
      final DataExportRepository repository = DataExportRepository(api);

      final ExportRequestResult result = await repository.requestExport(
        _workspace,
        'profile-1',
      );

      expect(result.id, 'export-1');
      expect(result.status, 'queued');
      expect(
        api.requestedPaths.single,
        '/workspaces/ws-1/profiles/profile-1/exports',
      );
      expect(api.lastPostData, isEmpty);
    });

    test('maps export_rate_limited and keeps the server wait', () async {
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 429,
          code: 'export_rate_limited',
          message: 'rate limited',
          retryAfterSeconds: 7200,
        ),
      );
      final DataExportRepository repository = DataExportRepository(api);

      await expectLater(
        repository.requestExport(_workspace, 'profile-1'),
        throwsA(
          isA<ExportRateLimited>().having(
            (ExportRateLimited failure) => failure.retryAfterSeconds,
            'retryAfterSeconds',
            7200,
          ),
        ),
      );
    });

    test('maps export_not_permitted', () async {
      final StubApiClient api = StubApiClient(
        failure: const ApiFailure(
          status: 403,
          code: 'export_not_permitted',
          message: 'forbidden',
        ),
      );
      final DataExportRepository repository = DataExportRepository(api);

      await expectLater(
        repository.requestExport(_workspace, 'profile-1'),
        throwsA(isA<ExportNotPermitted>()),
      );
    });

    test('maps export_expired and export_not_ready', () async {
      final StubApiClient expiredApi = StubApiClient(
        failure: const ApiFailure(
          status: 410,
          code: 'export_expired',
          message: 'expired',
        ),
      );
      final DataExportRepository expiredRepository = DataExportRepository(
        expiredApi,
      );

      await expectLater(
        expiredRepository.exportLink(_workspace, 'profile-1', 'export-1'),
        throwsA(isA<ExportExpired>()),
      );

      final StubApiClient notReadyApi = StubApiClient(
        failure: const ApiFailure(
          status: 409,
          code: 'export_not_ready',
          message: 'not ready',
        ),
      );
      final DataExportRepository notReadyRepository = DataExportRepository(
        notReadyApi,
      );

      await expectLater(
        notReadyRepository.exportStatus(_workspace, 'profile-1', 'export-1'),
        throwsA(isA<ExportNotReady>()),
      );
    });
  });
}
