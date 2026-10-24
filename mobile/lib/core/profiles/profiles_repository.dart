import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/network_providers.dart';
import 'profile.models.dart';

/// Reads the profile picker list.
///
/// Visibility is decided by the server, one profile at a time
/// (`health_profile.read`). The client never filters by role or by the
/// dashboard family card, which needs `person.read` and would hide profiles a
/// granted caregiver may still enter.
class ProfilesRepository {
  ProfilesRepository(this._api);

  final ApiClient _api;

  Future<ProfileSelection> load(String workspaceId) async {
    if (workspaceId.isEmpty) {
      return ProfileSelection.empty;
    }

    final Map<String, dynamic> body = await _api.get(
      '/workspaces/$workspaceId/profiles',
    );

    return parseProfileSelection(body['data']);
  }
}

final Provider<ProfilesRepository> profilesRepositoryProvider =
    Provider<ProfilesRepository>(
      (Ref ref) => ProfilesRepository(ref.watch(apiClientProvider)),
    );
