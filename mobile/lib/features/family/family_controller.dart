import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/workspace/current_workspace.dart';
import 'family_models.dart';
import 'family_repository.dart';

/// The result of a create call, already mapped to what the sheet does. The
/// server failure never reaches a widget as a raw exception.
sealed class InviteOutcome {
  const InviteOutcome();
}

final class InviteSuccess extends InviteOutcome {
  const InviteSuccess(this.invitation);

  final FamilyInvitation invitation;
}

/// `422`: a sentence per field, rendered next to that field.
final class InviteValidationFailure extends InviteOutcome {
  const InviteValidationFailure({
    required this.fieldErrors,
    required this.message,
  });

  final Map<String, String> fieldErrors;
  final String message;
}

/// `403`: the actor may not invite. The copy is the calm sentence from the
/// ticket, not the server's terse problem title.
final class InviteForbidden extends InviteOutcome {
  const InviteForbidden([this.message = messageText]);

  static const String messageText = 'คุณไม่มีสิทธิ์เชิญสมาชิก';

  final String message;
}

/// `404` / `409`: the request cannot be completed for this address. The API
/// deliberately does not disclose whether the address already belongs to a
/// member (neutral rule), so both statuses share one non-committal sentence
/// and the server's own wording is never rendered.
final class InviteUnavailable extends InviteOutcome {
  const InviteUnavailable([this.message = messageText]);

  static const String messageText =
      'ยังส่งคำเชิญไปยังอีเมลนี้ไม่ได้ กรุณาตรวจสอบอีกครั้ง';

  final String message;
}

/// Transport, unauthenticated, or any other failure with a safe Thai sentence.
final class InviteFailure extends InviteOutcome {
  const InviteFailure([this.message = ApiFailure.genericMessage]);

  final String message;
}

/// The result of a revoke call.
sealed class RevokeInvitationOutcome {
  const RevokeInvitationOutcome();
}

final class RevokeInvitationSuccess extends RevokeInvitationOutcome {
  const RevokeInvitationSuccess();
}

final class RevokeInvitationFailure extends RevokeInvitationOutcome {
  const RevokeInvitationFailure([this.message = ApiFailure.genericMessage]);

  final String message;
}

/// Loads the workspace member list and the pending invitations, and applies
/// the invite/revoke writes one at a time.
class FamilyController extends AsyncNotifier<FamilyData> {
  /// Matrix A: owner, legal guardian and workspace admin hold the invite
  /// action; caregiver, viewer and platform roles do not.
  static const Set<String> _inviteRoles = <String>{
    'profile_owner',
    'legal_guardian',
    'workspace_admin',
  };

  @override
  Future<FamilyData> build() async {
    final FamilyRepository repository = ref.watch(familyRepositoryProvider);
    final WorkspaceContext context = await ref.watch(
      currentWorkspaceProvider.future,
    );

    if (!context.hasWorkspace) {
      return FamilyData.noWorkspace;
    }

    List<FamilyMembership> members;
    bool canReadMembers = true;

    try {
      members = await repository.list(context);
    } on ApiFailure catch (failure) {
      // Matrix A denies the member list to viewers and caregivers. Export is a
      // separate permission, so the page must still be able to show that card
      // instead of turning the whole tab into a permission wall.
      if (!failure.isPermissionDenied) {
        rethrow;
      }

      canReadMembers = false;
      members = const <FamilyMembership>[];
    }

    return FamilyData(
      hasWorkspace: true,
      canInvite: canReadMembers && _inviteRoles.contains(context.role),
      canReadMembers: canReadMembers,
      profileId: context.profileId,
      members: members,
      invitations: canReadMembers
          ? await _knownInvitations(repository, context)
          : const <FamilyInvitation>[],
    );
  }

  void retry() {
    ref.invalidateSelf();
  }

  /// Issues an invitation and appends it to the pending list, so its expiry
  /// and link stay available for this session.
  Future<InviteOutcome> invite({
    required String email,
    required String role,
  }) async {
    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );

      if (!context.hasWorkspace) {
        return const InviteFailure(
          'ไม่พบครอบครัวที่เลือก กรุณาลองใหม่อีกครั้ง',
        );
      }

      final FamilyInvitation invitation = await ref
          .read(familyRepositoryProvider)
          .invite(context, email: email, role: role);

      _addInvitation(invitation);

      return InviteSuccess(invitation);
    } on ApiFailure catch (failure) {
      return _outcomeFor(failure);
    } on Object {
      return const InviteFailure();
    }
  }

  /// Revokes a pending invitation. The row is removed only after the server
  /// confirms, so a failed call leaves the list untouched.
  Future<RevokeInvitationOutcome> revokeInvitation(
    FamilyInvitation invitation,
  ) async {
    try {
      final WorkspaceContext context = await ref.read(
        currentWorkspaceProvider.future,
      );

      if (!context.hasWorkspace) {
        return const RevokeInvitationFailure(
          'ไม่พบครอบครัวที่เลือก กรุณาลองใหม่อีกครั้ง',
        );
      }

      await ref
          .read(familyRepositoryProvider)
          .revokeInvitation(context, invitation.id);

      _removeInvitation(invitation.id);

      return const RevokeInvitationSuccess();
    } on ApiFailure catch (failure) {
      if (failure.status == 403) {
        return const RevokeInvitationFailure('คุณไม่มีสิทธิ์ยกเลิกคำเชิญ');
      }

      if (failure.status == 404) {
        return const RevokeInvitationFailure(
          'ไม่พบคำเชิญนี้ อาจถูกยกเลิกไปแล้ว กรุณาโหลดใหม่',
        );
      }

      return RevokeInvitationFailure(failure.message);
    } on Object {
      return const RevokeInvitationFailure();
    }
  }

  /// The invitation list is supplementary: when the deployment does not expose
  /// it (or denies it) the member list must still render, and the section
  /// stays hidden instead of turning the tab into an error state.
  Future<List<FamilyInvitation>> _knownInvitations(
    FamilyRepository repository,
    WorkspaceContext context,
  ) async {
    try {
      return await repository.listInvitations(context);
    } on Object {
      return const <FamilyInvitation>[];
    }
  }

  void _addInvitation(FamilyInvitation invitation) {
    final FamilyData? current = state.value;

    if (current == null) {
      return;
    }

    final List<FamilyInvitation> next = List<FamilyInvitation>.of(
      current.invitations,
    )..removeWhere(
        (FamilyInvitation existing) =>
            existing.id == invitation.id ||
            (invitation.email.isNotEmpty &&
                existing.email.toLowerCase() ==
                    invitation.email.toLowerCase()),
      );

    next.insert(0, invitation);
    state = AsyncData<FamilyData>(current.withInvitations(next));
  }

  void _removeInvitation(String invitationId) {
    final FamilyData? current = state.value;

    if (current == null) {
      return;
    }

    final List<FamilyInvitation> next = current.invitations
        .where((FamilyInvitation invitation) => invitation.id != invitationId)
        .toList(growable: false);

    state = AsyncData<FamilyData>(current.withInvitations(next));
  }

  static InviteOutcome _outcomeFor(ApiFailure failure) {
    if (failure.status == 422) {
      return InviteValidationFailure(
        fieldErrors: failure.fieldErrors,
        message: failure.message,
      );
    }

    if (failure.status == 403) {
      return const InviteForbidden();
    }

    if (failure.status == 404 || failure.status == 409) {
      return const InviteUnavailable();
    }

    return InviteFailure(failure.message);
  }
}

final AsyncNotifierProvider<FamilyController, FamilyData>
familyControllerProvider = AsyncNotifierProvider<FamilyController, FamilyData>(
  FamilyController.new,
  isAutoDispose: true,
  retry: (int retryCount, Object error) => null,
);
