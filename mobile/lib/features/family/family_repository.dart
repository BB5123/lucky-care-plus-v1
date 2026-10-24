import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_client.dart';
import '../../core/network/api_failure.dart';
import '../../core/network/json_read.dart';
import '../../core/network/network_providers.dart';
import '../../core/workspace/current_workspace.dart';
import 'family_models.dart';

/// Reads the workspace member list and manages invitations. Matrix A denies
/// the member list to viewers and caregivers; that 403 is an expected state,
/// not a fault. Only the owner, the legal guardian and the workspace admin may
/// invite.
class FamilyRepository {
  FamilyRepository(this._api);

  final ApiClient _api;

  Future<List<FamilyMembership>> list(WorkspaceContext context) async {
    final Map<String, dynamic> body = await _api.get(
      '/workspaces/${_workspaceId(context)}/members',
    );

    return readList(body['data'])
        .map((Object? entry) => FamilyMembership.fromJson(readMap(entry)))
        .where((FamilyMembership member) => member.id.isNotEmpty)
        .toList(growable: false);
  }

  /// Pending invitations for the workspace. The list endpoint is optional for
  /// the screen: a deployment without it answers 404/405 and the controller
  /// simply shows the invitations issued in this session.
  Future<List<FamilyInvitation>> listInvitations(
    WorkspaceContext context,
  ) async {
    final Map<String, dynamic> body = await _api.get(
      '/workspaces/${_workspaceId(context)}/invitations',
    );

    return readList(body['data'])
        .map((Object? entry) => FamilyInvitation.fromJson(readMap(entry)))
        .where((FamilyInvitation invitation) => invitation.id.isNotEmpty)
        .toList(growable: false);
  }

  /// Issues an invitation. The response carries the only copy of the token,
  /// which is why [FamilyInvitation] keeps it in memory only.
  Future<FamilyInvitation> invite(
    WorkspaceContext context, {
    required String email,
    required String role,
  }) async {
    if (!invitableRoleValues.contains(role)) {
      throw const ApiFailure(
        status: 422,
        code: 'validation',
        message: 'กรุณาเลือกบทบาทที่ได้รับอนุญาต',
        fieldErrors: <String, String>{
          'role': 'กรุณาเลือกบทบาทที่ได้รับอนุญาต',
        },
      );
    }

    final Map<String, dynamic> body = await _api.post(
      '/workspaces/${_workspaceId(context)}/invitations',
      data: <String, dynamic>{'email': email, 'role': role},
    );
    final FamilyInvitation invitation = FamilyInvitation.fromJson(
      readMap(body['data']),
    );

    // Without an id or a token the response cannot be shown or shared.
    if (invitation.id.isEmpty && invitation.token.isEmpty) {
      throw ApiFailure.protocol;
    }

    return invitation;
  }

  Future<void> revokeInvitation(
    WorkspaceContext context,
    String invitationId,
  ) async {
    if (invitationId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    await _api.delete(
      '/workspaces/${_workspaceId(context)}/invitations/$invitationId',
    );
  }

  String _workspaceId(WorkspaceContext context) {
    final String? workspaceId = context.workspaceId;

    if (workspaceId == null || workspaceId.isEmpty) {
      throw const ApiFailure(
        status: 404,
        code: 'out_of_scope',
        message: 'ไม่พบข้อมูลที่ร้องขอ',
      );
    }

    return workspaceId;
  }
}

final Provider<FamilyRepository> familyRepositoryProvider =
    Provider<FamilyRepository>(
      (Ref ref) => FamilyRepository(ref.watch(apiClientProvider)),
    );
