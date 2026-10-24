import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/api_failure.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';
import '../workspace/current_workspace.dart';
import 'ai_models.dart';

/// Reads the three AI surfaces for one profile:
///
///  - `POST .../ai/summaries` — a draft trend summary for a date window;
///  - `POST .../ai/knowledge-answers` — a cited answer from the reference corpus;
///  - `POST .../ai/extractions` — a draft read from a photo of a medication
///    label or a device screen.
///
/// The client never invents a permission or a profile: the workspace context
/// comes from `/me` + the authorized profile list (N-01) and [profileId] is
/// only an override for that same server-authorized value.
///
/// Four outcomes are typed instead of being reduced to [ApiFailure], because
/// the screen must treat them differently from an error (`.ai/MOBILE_DESIGN.md`
/// §5/§9): [AiUnavailable] (`503 ai_disabled`), [AiVisionUnavailable]
/// (`503 ai_vision_unavailable`), [AiQuotaExceeded] (`429`) and [AiNoMatch]
/// (`422 knowledge_not_found`, an empty result). Everything else — including a
/// 403/404 decision and a malformed envelope — stays an [ApiFailure] so the
/// shared error/empty states keep working.
class AiRepository {
  AiRepository(this._api);

  final ApiClient _api;

  /// `from`/`to` become `period_from`/`period_to` (`YYYY-MM-DD`). The server
  /// defaults to the last 30 days and caps the window at 180 days.
  Future<AiSummary> summarize(
    WorkspaceContext context, {
    String? profileId,
    DateTime? from,
    DateTime? to,
  }) async {
    final Map<String, dynamic> body = await _guard(() {
      return _api.post(
        '${_profilePath(context, profileId)}/ai/summaries',
        data: <String, dynamic>{
          if (from != null) 'period_from': _day(from),
          if (to != null) 'period_to': _day(to),
        },
      );
    });
    final AiSummary summary = AiSummary.fromJson(readMap(body['data']));

    if (!summary.isValid) {
      throw ApiFailure.protocol;
    }

    return summary;
  }

  Future<KnowledgeAnswer> askKnowledge(
    WorkspaceContext context,
    String question, {
    String? profileId,
  }) async {
    final String trimmed = question.trim();

    if (trimmed.isEmpty) {
      throw const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'กรุณาพิมพ์คำถามก่อนส่ง',
      );
    }

    final Map<String, dynamic> body = await _guard(() {
      return _api.post(
        '${_profilePath(context, profileId)}/ai/knowledge-answers',
        data: <String, dynamic>{'question': trimmed},
      );
    });
    final KnowledgeAnswer answer = KnowledgeAnswer.fromJson(
      readMap(body['data']),
    );

    if (!answer.isValid) {
      throw ApiFailure.protocol;
    }

    return answer;
  }

  /// Kept for the earlier card contract; delegates to [askKnowledge].
  Future<KnowledgeAnswer> ask(
    WorkspaceContext context,
    String question, {
    String? profileId,
  }) {
    return askKnowledge(context, question, profileId: profileId);
  }

  /// Asks the gateway to read a photo that was already uploaded (TASK P-3).
  ///
  /// The caller must upload through [DocumentsRepository] first and pass the
  /// returned id; this method never accepts bytes. The response is a **draft**
  /// only: `requires_confirmation` is always true and no health row exists
  /// until the user saves through the normal medication/measurement endpoint
  /// (AIG-007). The draft keeps no document id, so the review sheet cannot
  /// render one (N-02).
  Future<AiExtractionDraft> extract(
    WorkspaceContext context, {
    required String documentId,
    required AiExtractionKind kind,
    String? profileId,
  }) async {
    final String identifier = documentId.trim();

    if (identifier.isEmpty) {
      throw const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'ไม่พบเอกสารสำหรับอ่านภาพ',
      );
    }

    final Map<String, dynamic> body = await _guard(() {
      return _api.post(
        '${_profilePath(context, profileId)}/ai/extractions',
        data: <String, dynamic>{
          'document_id': identifier,
          'kind': kind.apiValue,
        },
      );
    });
    final AiExtractionDraft draft = AiExtractionDraft.fromJson(
      readMap(body['data']),
    );

    if (!draft.isValid) {
      throw ApiFailure.protocol;
    }

    return draft;
  }

  /// Maps the "not an error" outcomes, rethrows everything else.
  Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiFailure catch (failure) {
      if (failure.code == 'ai_disabled') {
        throw const AiUnavailable();
      }

      if (failure.code == 'ai_vision_unavailable') {
        throw const AiVisionUnavailable();
      }

      if (failure.status == 429 || failure.code == 'ai_quota_exceeded') {
        throw const AiQuotaExceeded();
      }

      if (failure.code == 'knowledge_not_found') {
        throw const AiNoMatch();
      }

      rethrow;
    }
  }

  String _profilePath(WorkspaceContext context, String? profileId) {
    final String? workspaceId = context.workspaceId;
    final String? profile = profileId ?? context.profileId;

    if (workspaceId == null ||
        workspaceId.isEmpty ||
        profile == null ||
        profile.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return '/workspaces/$workspaceId/profiles/$profile';
  }

  static String _day(DateTime value) {
    final String month = value.month.toString().padLeft(2, '0');
    final String day = value.day.toString().padLeft(2, '0');

    return '${value.year.toString().padLeft(4, '0')}-$month-$day';
  }
}

final Provider<AiRepository> aiRepositoryProvider = Provider<AiRepository>(
  (Ref ref) => AiRepository(ref.watch(apiClientProvider)),
);
