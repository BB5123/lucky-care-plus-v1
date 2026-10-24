import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/api_failure.dart';
import '../network/network_providers.dart';
import '../workspace/current_workspace.dart';
import 'data_export_models.dart';

/// Client for the PDPA data-export surface
/// (`.../workspaces/{workspace}/profiles/{profile}/exports`).
///
/// Request, status and link are three separate permissions on the server; the
/// client never assumes that being allowed to request also allows downloading.
/// The signed URL is returned to the caller and never stored here, because it
/// is a short-lived capability (F-06 / F-08).
class DataExportRepository {
  DataExportRepository(this._api);

  final ApiClient _api;

  Future<ExportRequestResult> requestExport(
    WorkspaceContext context,
    String profileId,
  ) async {
    try {
      final Map<String, dynamic> body = await _api.post(
        _exportsPath(context, profileId),
        data: const <String, dynamic>{},
      );
      final ExportRequestResult? result = ExportRequestResult.parse(
        body['data'],
      );

      if (result == null) {
        throw const ExportUnavailable(exportRequestUnreadableMessage);
      }

      return result;
    } on ExportFailure {
      rethrow;
    } on ApiFailure catch (failure) {
      throw _mapFailure(failure);
    }
  }

  Future<ExportSnapshot> exportStatus(
    WorkspaceContext context,
    String profileId,
    String id,
  ) async {
    try {
      final Map<String, dynamic> body = await _api.get(
        '${_exportsPath(context, profileId)}/${Uri.encodeComponent(id)}',
      );
      final ExportSnapshot? snapshot = ExportSnapshot.parse(body['data']);

      if (snapshot == null) {
        throw const ExportUnavailable(exportStatusUnreadableMessage);
      }

      return snapshot;
    } on ExportFailure {
      rethrow;
    } on ApiFailure catch (failure) {
      throw _mapFailure(failure);
    }
  }

  Future<ExportLink> exportLink(
    WorkspaceContext context,
    String profileId,
    String id,
  ) async {
    try {
      final Map<String, dynamic> body = await _api.post(
        '${_exportsPath(context, profileId)}/${Uri.encodeComponent(id)}/link',
        data: const <String, dynamic>{},
      );
      final ExportLink? link = ExportLink.parse(body['data']);

      if (link == null) {
        throw const ExportUnavailable(exportLinkUnreadableMessage);
      }

      return link;
    } on ExportFailure {
      rethrow;
    } on ApiFailure catch (failure) {
      throw _mapFailure(failure);
    }
  }

  String _exportsPath(WorkspaceContext context, String profileId) {
    final String? workspaceId = context.workspaceId;

    if (workspaceId == null || workspaceId.isEmpty) {
      throw const ExportUnavailable(exportWorkspaceMissingMessage);
    }

    if (profileId.isEmpty) {
      throw const ExportUnavailable(exportProfileMissingMessage);
    }

    return '/workspaces/${Uri.encodeComponent(workspaceId)}'
        '/profiles/${Uri.encodeComponent(profileId)}/exports';
  }

  /// Maps the four codes the export API names explicitly. The status is the
  /// fallback because a proxy may strip the problem body; anything else keeps
  /// [ApiFailure]'s already-sanitized sentence.
  ExportFailure _mapFailure(ApiFailure failure) {
    if (failure.code == 'export_rate_limited' || failure.status == 429) {
      return ExportRateLimited(
        retryAfterSeconds:
            failure.retryAfterSeconds ?? defaultExportRateLimitSeconds,
      );
    }

    if (failure.code == 'export_not_permitted' || failure.status == 403) {
      return const ExportNotPermitted();
    }

    if (failure.code == 'export_expired' || failure.status == 410) {
      return const ExportExpired();
    }

    if (failure.code == 'export_not_ready' || failure.status == 409) {
      return const ExportNotReady();
    }

    return ExportUnavailable(failure.message);
  }
}

final Provider<DataExportRepository> dataExportRepositoryProvider =
    Provider<DataExportRepository>(
      (Ref ref) => DataExportRepository(ref.watch(apiClientProvider)),
    );
