import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/api_failure.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';
import '../workspace/current_workspace.dart';
import 'medication_models.dart';

/// Medication plans, reported doses and the server's adherence aggregate.
///
/// The repository never filters by permission: a 403/404 is surfaced as a
/// decision and the medication segment renders an explanation instead.
class MedicationRepository {
  MedicationRepository(this._api);

  final ApiClient _api;

  /// Plans for the current profile, active first and discontinued after them.
  Future<List<MedicationPlan>> listPlans(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.get(
      '${_profilePath(context)}/medication-plans',
      query: <String, dynamic>{'limit': 100},
    );

    return orderMedicationPlans(
      readList(body['data'])
          .map((Object? entry) => MedicationPlan.fromJson(readMap(entry)))
          .where((MedicationPlan plan) => plan.id.isNotEmpty)
          .toList(growable: false),
    );
  }

  /// Reports one dose. A skipped dose without a note is refused by the server;
  /// the sheet blocks it locally first.
  Future<MedicationRecord> recordDose(
    WorkspaceContext context, {
    required String planId,
    required String status,
    String? note,
    String? scheduledFor,
  }) async {
    final Map<String, dynamic> payload = <String, dynamic>{
      'plan_id': planId,
      'status': status,
    };
    final String? trimmedNote = note?.trim();
    final String? slot = scheduledFor;

    if (trimmedNote != null && trimmedNote.isNotEmpty) {
      payload['note'] = trimmedNote;
    }

    if (slot != null && slot.isNotEmpty) {
      payload['scheduled_for'] = slot;
    }

    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/medication-records',
      data: payload,
    );

    return MedicationRecord.fromJson(readMap(body['data']));
  }

  /// The server's rolling adherence aggregate, defaulting to seven days.
  Future<List<MedicationAdherence>> adherence(
    WorkspaceContext context, {
    int days = 7,
  }) async {
    final Map<String, dynamic> body = await _api.get(
      '${_profilePath(context)}/medication-adherence',
      query: <String, dynamic>{'days': days},
    );

    return readList(body['data'])
        .map((Object? entry) => MedicationAdherence.fromJson(readMap(entry)))
        .where((MedicationAdherence entry) => entry.planId.isNotEmpty)
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

final Provider<MedicationRepository> medicationRepositoryProvider =
    Provider<MedicationRepository>(
      (Ref ref) => MedicationRepository(ref.watch(apiClientProvider)),
    );
