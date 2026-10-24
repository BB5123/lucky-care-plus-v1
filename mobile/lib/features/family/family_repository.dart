import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'family_models.dart';

/// Reads the workspace member list. Matrix A denies this to viewers and
/// caregivers; that 403 is an expected state, not a fault.
class FamilyRepository {
  FamilyRepository(this._api);

  final ApiClient _api;

  Future<List<FamilyMembership>> list(WorkspaceContext context) async {
    final String? workspaceId = context.workspaceId;

    if (workspaceId == null || workspaceId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    final Map<String, dynamic> body = await _api.get(
      '/workspaces/$workspaceId/members',
    );

    return readList(body['data'])
        .map((Object? entry) => FamilyMembership.fromJson(readMap(entry)))
        .where((FamilyMembership member) => member.id.isNotEmpty)
        .toList(growable: false);
  }
}

final Provider<FamilyRepository> familyRepositoryProvider =
    Provider<FamilyRepository>(
      (Ref ref) => FamilyRepository(ref.watch(apiClientProvider)),
    );
