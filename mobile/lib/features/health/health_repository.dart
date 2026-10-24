import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'health_models.dart';

/// Reads and writes measurements for the workspace's current profile.
///
/// It never filters by permission: a 403/404 is surfaced as a decision and the
/// page renders an explanation instead of an error banner.
class HealthRepository {
  HealthRepository(this._api);

  final ApiClient _api;

  Future<List<HealthMeasurement>> list(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.get(
      '${_profilePath(context)}/measurements',
      query: <String, dynamic>{'limit': 100},
    );

    return readList(body['data'])
        .map((Object? entry) => HealthMeasurement.fromJson(readMap(entry)))
        .where((HealthMeasurement measurement) => measurement.id.isNotEmpty)
        .toList(growable: false);
  }

  Future<HealthMeasurement> create(
    WorkspaceContext context,
    CreateMeasurementInput input,
  ) async {
    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/measurements',
      data: input.toJson(),
    );

    return HealthMeasurement.fromJson(readMap(body['data']));
  }

  String _profilePath(WorkspaceContext context) {
    final String? workspaceId = context.workspaceId;
    final String? profileId = context.profileId;

    if (workspaceId == null ||
        workspaceId.isEmpty ||
        profileId == null ||
        profileId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return '/workspaces/$workspaceId/profiles/$profileId';
  }
}

final Provider<HealthRepository> healthRepositoryProvider =
    Provider<HealthRepository>(
      (Ref ref) => HealthRepository(ref.watch(apiClientProvider)),
    );
