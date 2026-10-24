import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'appointment_models.dart';

/// Read-only appointment access for this slice.
class AppointmentsRepository {
  AppointmentsRepository(this._api);

  final ApiClient _api;

  Future<List<Appointment>> list(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.get(
      '${_profilePath(context)}/appointments',
      query: <String, dynamic>{'limit': 100},
    );

    return readList(body['data'])
        .map((Object? entry) => Appointment.fromJson(readMap(entry)))
        .where((Appointment appointment) => appointment.id.isNotEmpty)
        .toList(growable: false);
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

final Provider<AppointmentsRepository> appointmentsRepositoryProvider =
    Provider<AppointmentsRepository>(
      (Ref ref) => AppointmentsRepository(ref.watch(apiClientProvider)),
    );
