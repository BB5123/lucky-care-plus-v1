import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_models.dart';
import '../auth/auth_repository.dart';
import '../network/api_client.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';

/// The workspace and default health profile every tab addresses.
///
/// The client never chooses a tenant, workspace or profile on its own: it
/// reuses what `/me` and the dashboard aggregate already resolved
/// (N-01). The server remains the authority for every later read.
class WorkspaceContext {
  const WorkspaceContext({
    this.workspaceId,
    this.workspaceName,
    this.role,
    this.profileId,
    this.profileDisplayName,
  });

  final String? workspaceId;
  final String? workspaceName;
  final String? role;

  /// The first profile the dashboard family card exposed, or `null` when the
  /// actor has no visible profile (the server chose not to reveal one).
  final String? profileId;
  final String? profileDisplayName;

  bool get hasWorkspace => _isPresent(workspaceId);

  bool get hasProfile => _isPresent(profileId);

  static bool _isPresent(String? value) => value != null && value.isNotEmpty;

  static const WorkspaceContext absent = WorkspaceContext();
}

/// Resolves the current workspace exactly the way the dashboard does: `/me`
/// picks the first membership, then the aggregate resolves the visible family
/// (and therefore the first profile).
class WorkspaceContextRepository {
  WorkspaceContextRepository(this._auth, this._api);

  final AuthRepository _auth;
  final ApiClient _api;

  Future<WorkspaceContext> load() async {
    final MeResponse me = await _auth.me();

    if (me.workspaces.isEmpty) {
      return WorkspaceContext.absent;
    }

    final MembershipSummary workspace = me.workspaces.first;
    final Map<String, dynamic> body = await _api.get(
      '/workspaces/${workspace.workspaceId}/dashboard',
    );
    final Map<String, dynamic> data = readMap(body['data']);
    final Map<String, dynamic> family = readMap(data['family']);

    String? profileId;
    String? profileDisplayName;

    if (readBool(family['visible'])) {
      Map<String, dynamic>? chosen;

      for (final Object? entry in readList(family['members'])) {
        final Map<String, dynamic> member = readMap(entry);
        final String candidate = readString(member['profile_id']);

        if (candidate.isEmpty) {
          continue;
        }

        // Prefer the actor's own profile (`ring: inner`) when the server
        // exposes one; otherwise use the first visible family member.
        if (chosen == null ||
            (readString(member['ring']) == 'inner' &&
                readString(chosen['ring']) != 'inner')) {
          chosen = member;
        }
      }

      if (chosen != null) {
        profileId = readString(chosen['profile_id']);
        final String name = readString(chosen['display_name']);
        profileDisplayName = name.isEmpty ? null : name;
      }
    }

    return WorkspaceContext(
      workspaceId: workspace.workspaceId,
      workspaceName: workspace.name,
      role: workspace.role,
      profileId: profileId,
      profileDisplayName: profileDisplayName,
    );
  }
}

final Provider<WorkspaceContextRepository> workspaceContextRepositoryProvider =
    Provider<WorkspaceContextRepository>(
      (Ref ref) => WorkspaceContextRepository(
        ref.watch(authRepositoryProvider),
        ref.watch(apiClientProvider),
      ),
    );

/// One shared context for every tab. A 403/404 is not retried automatically:
/// it is a decision, and the affected tab renders an explanation instead.
class CurrentWorkspaceController extends AsyncNotifier<WorkspaceContext> {
  @override
  Future<WorkspaceContext> build() {
    return ref.watch(workspaceContextRepositoryProvider).load();
  }

  void retry() {
    ref.invalidateSelf();
  }
}

final AsyncNotifierProvider<CurrentWorkspaceController, WorkspaceContext>
currentWorkspaceProvider =
    AsyncNotifierProvider<CurrentWorkspaceController, WorkspaceContext>(
      CurrentWorkspaceController.new,
      isAutoDispose: true,
      retry: (int retryCount, Object error) => null,
    );
