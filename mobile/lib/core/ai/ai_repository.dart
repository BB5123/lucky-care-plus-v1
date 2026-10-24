import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/api_client.dart';
import '../network/api_failure.dart';
import '../network/json_read.dart';
import '../network/network_providers.dart';
import '../workspace/current_workspace.dart';
import 'ai_models.dart';

/// Reads the two AI surfaces for the current profile:
///
///  - `POST .../ai/summaries` — a draft trend summary;
///  - `POST .../ai/knowledge-answers` — a cited answer from the reference corpus.
///
/// The client never invents a permission or a profile: the workspace context
/// comes from `/me` + the authorized profile list (N-01), and every 403/404 is
/// surfaced as [ApiFailure] for the screen to explain.
class AiRepository {
  AiRepository(this._api);

  final ApiClient _api;

  Future<AiSummary> summarize(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/ai/summaries',
      data: const <String, dynamic>{},
    );
    final AiSummary summary = AiSummary.fromJson(readMap(body['data']));

    if (!summary.isValid) {
      throw ApiFailure.protocol;
    }

    return summary;
  }

  Future<KnowledgeAnswer> ask(
    WorkspaceContext context,
    String question,
  ) async {
    final String trimmed = question.trim();

    if (trimmed.isEmpty) {
      throw const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'กรุณาพิมพ์คำถามก่อนส่ง',
      );
    }

    final Map<String, dynamic> body = await _api.post(
      '${_profilePath(context)}/ai/knowledge-answers',
      data: <String, dynamic>{'question': trimmed},
    );
    final KnowledgeAnswer answer = KnowledgeAnswer.fromJson(
      readMap(body['data']),
    );

    if (!answer.isValid) {
      throw ApiFailure.protocol;
    }

    return answer;
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

final Provider<AiRepository> aiRepositoryProvider = Provider<AiRepository>(
  (Ref ref) => AiRepository(ref.watch(apiClientProvider)),
);
