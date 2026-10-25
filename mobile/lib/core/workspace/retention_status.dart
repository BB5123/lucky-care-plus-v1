import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../network/network_providers.dart';
import 'current_workspace.dart';

/// The account lifecycle the API reports on `GET /workspaces/{id}`.
///
/// Mirrors the five states of `RETENTION-MODEL.md` §2. The client only displays
/// them: the server's 409/410 is the authority on what a non-active account may
/// do, and an unknown value is treated as "no status" rather than guessed.
enum RetentionState { active, readOnly, archived, scheduledDeletion, deleted }

RetentionState? _parseState(Object? value) {
  switch (value) {
    case 'active':
      return RetentionState.active;
    case 'read_only':
      return RetentionState.readOnly;
    case 'archived':
      return RetentionState.archived;
    case 'scheduled_deletion':
      return RetentionState.scheduledDeletion;
    case 'deleted':
      return RetentionState.deleted;
    default:
      return null;
  }
}

class RetentionStatus {
  const RetentionStatus({this.state, this.expiresAt});

  final RetentionState? state;
  final String? expiresAt;

  static const RetentionStatus none = RetentionStatus();

  bool get isRestricted => state != null && state != RetentionState.active;

  String? get bannerTitle {
    switch (state) {
      case RetentionState.readOnly:
        return 'บัญชีนี้อยู่ในสถานะอ่านอย่างเดียว';
      case RetentionState.archived:
        return 'บัญชีนี้ถูกเก็บถาวร';
      case RetentionState.scheduledDeletion:
        return 'บัญชีนี้อยู่ในคิวการลบ';
      default:
        return null;
    }
  }

  String? get bannerDetail {
    switch (state) {
      case RetentionState.readOnly:
        return 'ยังอ่านและส่งออกข้อมูลได้ แต่การแก้ไขถูกปิดไว้จนกว่าจะกลับมาใช้งานต่อ '
            'กรุณาติดต่อผู้ดูแลหากต้องการกู้คืน';
      case RetentionState.archived:
        return 'ข้อมูลไม่ถูกโหลดเข้าสู่แอปแล้ว ท่านยังส่งออกข้อมูลได้ '
            'และสามารถติดต่อผู้ดูแลเพื่อกู้คืนบัญชี';
      case RetentionState.scheduledDeletion:
        return 'กรุณาส่งออกข้อมูลที่ต้องการเก็บไว้ หรือติดต่อผู้ดูแลก่อนถึงกำหนดลบ';
      default:
        return null;
    }
  }

  factory RetentionStatus.fromJson(Object? payload) {
    if (payload is! Map) {
      return none;
    }

    final RetentionState? state = _parseState(payload['retention_state']);

    if (state == null) {
      return none;
    }

    final Object? expires = payload['retention_expires_at'];

    return RetentionStatus(
      state: state,
      expiresAt: expires is String ? expires : null,
    );
  }
}

/// Reads the account state for the selected workspace.
///
/// It is advisory UI: a failed read (or no workspace) yields [RetentionStatus.none]
/// instead of an error screen, because every data screen already reports its own
/// authorization outcome and the API refuses writes regardless.
final FutureProvider<RetentionStatus> workspaceRetentionProvider =
    FutureProvider<RetentionStatus>((Ref ref) async {
      try {
        final context = await ref.watch(currentWorkspaceProvider.future);
        final String? workspaceId = context.workspaceId;

        if (workspaceId == null || workspaceId.isEmpty) {
          return RetentionStatus.none;
        }

        final Map<String, dynamic> body = await ref
            .watch(apiClientProvider)
            .get('/workspaces/$workspaceId');

        return RetentionStatus.fromJson(body['data']);
      } on Object {
        return RetentionStatus.none;
      }
    });
