import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_client.dart';
import 'package:lucky_care/core/network/network_providers.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/core/workspace/retention_status.dart';

/// A client that records the path and answers with a fixed body.
class _FakeApiClient extends ApiClient {
  _FakeApiClient(this.body);

  final Map<String, dynamic> body;
  final List<String> requested = <String>[];

  @override
  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) async {
    requested.add(path);

    return body;
  }
}

class _FailingApiClient extends ApiClient {
  @override
  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) async {
    throw Exception('network down');
  }
}

class _StubWorkspaceController extends CurrentWorkspaceController {
  _StubWorkspaceController(this.context);

  final WorkspaceContext context;

  @override
  Future<WorkspaceContext> build() async => context;
}

void main() {
  test('RET-M05 the provider reads the workspace metadata endpoint', () async {
    final _FakeApiClient api = _FakeApiClient(<String, dynamic>{
      'data': <String, Object?>{
        'retention_state': 'archived',
        'retention_expires_at': '2027-01-01T00:00:00+00:00',
      },
    });

    final ProviderContainer container = ProviderContainer(
      overrides: <Override>[
        currentWorkspaceProvider.overrideWith(
          () => _StubWorkspaceController(
            const WorkspaceContext(workspaceId: 'ws-1'),
          ),
        ),
        apiClientProvider.overrideWithValue(api),
      ],
    );

    final RetentionStatus status = await container.read(
      workspaceRetentionProvider.future,
    );

    expect(status.state, RetentionState.archived);
    expect(status.isRestricted, isTrue);
    expect(status.expiresAt, '2027-01-01T00:00:00+00:00');
    expect(api.requested, <String>['/workspaces/ws-1']);
  });

  test('RET-M06 a failed read is no status, never an error screen', () async {
    final ProviderContainer container = ProviderContainer(
      overrides: <Override>[
        currentWorkspaceProvider.overrideWith(
          () => _StubWorkspaceController(
            const WorkspaceContext(workspaceId: 'ws-1'),
          ),
        ),
        apiClientProvider.overrideWithValue(_FailingApiClient()),
      ],
    );

    final RetentionStatus status = await container.read(
      workspaceRetentionProvider.future,
    );

    expect(status.isRestricted, isFalse);
    expect(status.bannerTitle, isNull);
  });

  test('RET-M07 no workspace means no request and no status', () async {
    final _FakeApiClient api = _FakeApiClient(<String, dynamic>{
      'data': <String, Object?>{'retention_state': 'read_only'},
    });

    final ProviderContainer container = ProviderContainer(
      overrides: <Override>[
        currentWorkspaceProvider.overrideWith(
          () => _StubWorkspaceController(WorkspaceContext.absent),
        ),
        apiClientProvider.overrideWithValue(api),
      ],
    );

    final RetentionStatus status = await container.read(
      workspaceRetentionProvider.future,
    );

    expect(status.isRestricted, isFalse);
    expect(api.requested, isEmpty);
  });
}
