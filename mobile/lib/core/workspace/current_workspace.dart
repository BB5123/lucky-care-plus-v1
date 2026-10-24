import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../auth/auth_models.dart';
import '../auth/auth_repository.dart';
import '../profiles/profile.models.dart';
import '../profiles/profiles_repository.dart';

/// The workspace and health profiles every tab addresses.
///
/// The client never chooses a tenant, workspace or profile on its own: it
/// reuses the workspace `/me` resolved and the profile list the server
/// authorized (N-01). The list is the only source of `profile_id` values for
/// the health, appointments and timeline screens; the dashboard family card
/// needs `person.read` and is not a substitute.
class WorkspaceContext {
  const WorkspaceContext({
    this.workspaceId,
    this.workspaceName,
    this.role,
    this.profiles = const <HealthProfileSummary>[],
    this.currentProfile,
  });

  final String? workspaceId;
  final String? workspaceName;
  final String? role;

  /// Every profile the actor may read, in server order.
  final List<HealthProfileSummary> profiles;

  /// The profile to open first: `relationship == 'self'`, otherwise the first
  /// readable profile, otherwise `null` when the server returned nothing.
  final HealthProfileSummary? currentProfile;

  String? get profileId => currentProfile?.id;

  String? get profileDisplayName {
    final String? name = currentProfile?.displayName;

    return name == null || name.isEmpty ? null : name;
  }

  bool get hasWorkspace => _isPresent(workspaceId);

  bool get hasProfile => currentProfile != null;

  static bool _isPresent(String? value) => value != null && value.isNotEmpty;

  static const WorkspaceContext absent = WorkspaceContext();
}

/// Resolves the current workspace from `/me`, then asks the profile list which
/// profiles the actor may actually enter.
class WorkspaceContextRepository {
  WorkspaceContextRepository(this._auth, this._profiles);

  final AuthRepository _auth;
  final ProfilesRepository _profiles;

  Future<WorkspaceContext> load() async {
    final MeResponse me = await _auth.me();

    if (me.workspaces.isEmpty) {
      return WorkspaceContext.absent;
    }

    final MembershipSummary workspace = me.workspaces.first;
    final ProfileSelection selection = await _profiles.load(
      workspace.workspaceId,
    );

    return WorkspaceContext(
      workspaceId: workspace.workspaceId,
      workspaceName: workspace.name,
      role: workspace.role,
      profiles: selection.profiles,
      currentProfile: selection.currentProfile,
    );
  }
}

final Provider<WorkspaceContextRepository> workspaceContextRepositoryProvider =
    Provider<WorkspaceContextRepository>(
      (Ref ref) => WorkspaceContextRepository(
        ref.watch(authRepositoryProvider),
        ref.watch(profilesRepositoryProvider),
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
