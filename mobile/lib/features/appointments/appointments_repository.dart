import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'appointment_models.dart';

/// Appointment reads and writes for this slice.
///
/// Every mutation is scoped to the workspace/profile pair resolved from
/// `/me`; the client never chooses either (N-01). Updates and cancels send
/// `If-Match: <version>` and let the server own the optimistic-locking check.
class AppointmentsRepository {
  AppointmentsRepository(this._api);

  final ApiClient _api;

  /// The cancel endpoint requires a reason. The sheet only asks for
  /// confirmation, so the client records the safe, non-clinical provenance
  /// sentence; the server still validates the minimum length.
  static const String cancelReason = 'ยกเลิกจากแอปมือถือ';

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

  Future<Appointment> create(
    WorkspaceContext context, {
    String? profileId,
    required String title,
    required DateTime startsAt,
    int? durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    final Map<String, dynamic> data = <String, dynamic>{
      'title': title,
      'scheduled_at': _iso8601(startsAt),
    };

    if (durationMinutes != null) {
      data['duration_minutes'] = durationMinutes;
    }

    if (location != null) {
      data['location'] = location;
    }

    // The API names these `provider_name` and `purpose`; the mobile sheet
    // keeps the family-facing words "clinician" and "notes".
    if (clinician != null) {
      data['provider_name'] = clinician;
    }

    if (notes != null) {
      data['purpose'] = notes;
    }

    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context, profileId: profileId)}/appointments',
      data: data,
    );

    return Appointment.fromJson(readMap(body['data']));
  }

  /// Sends only the arguments the caller provides. `null` means "leave this
  /// field alone"; an empty string is a deliberate clear.
  Future<Appointment> update(
    WorkspaceContext context,
    String appointmentId, {
    required int version,
    String? title,
    DateTime? startsAt,
    int? durationMinutes,
    String? location,
    String? clinician,
    String? notes,
  }) async {
    final Map<String, dynamic> data = <String, dynamic>{};

    if (title != null) {
      data['title'] = title;
    }

    if (startsAt != null) {
      data['scheduled_at'] = _iso8601(startsAt);
    }

    if (durationMinutes != null) {
      data['duration_minutes'] = durationMinutes;
    }

    if (location != null) {
      data['location'] = location;
    }

    if (clinician != null) {
      data['provider_name'] = clinician;
    }

    if (notes != null) {
      data['purpose'] = notes;
    }

    final Map<String, dynamic> body = await _api.patch(
      '${_profilePath(context)}/appointments/$appointmentId',
      data: data,
      headers: <String, String>{'If-Match': version.toString()},
    );

    return Appointment.fromJson(readMap(body['data']));
  }

  Future<Appointment> cancel(
    WorkspaceContext context,
    String appointmentId,
    int version,
  ) async {
    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/appointments/$appointmentId/cancel',
      data: <String, dynamic>{'reason': cancelReason},
      headers: <String, String>{'If-Match': version.toString()},
    );

    return Appointment.fromJson(readMap(body['data']));
  }

  String _profilePath(WorkspaceContext context, {String? profileId}) {
    final String? workspaceId = context.workspaceId;
    final String? resolvedProfileId = profileId ?? context.profileId;

    if (workspaceId == null ||
        workspaceId.isEmpty ||
        resolvedProfileId == null ||
        resolvedProfileId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return '/workspaces/$workspaceId/profiles/$resolvedProfileId';
  }

  static String _iso8601(DateTime value) {
    return value.toUtc().toIso8601String();
  }
}

final Provider<AppointmentsRepository> appointmentsRepositoryProvider =
    Provider<AppointmentsRepository>(
      (Ref ref) => AppointmentsRepository(ref.watch(apiClientProvider)),
    );
