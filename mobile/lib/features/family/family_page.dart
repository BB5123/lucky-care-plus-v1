import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../design_system/state_views.dart';
import '../../design_system/tokens.dart';
import 'family_controller.dart';
import 'family_models.dart';
import 'invite_clipboard.dart';
import 'widgets/invitation_tile.dart';
import 'widgets/invite_sheet.dart';
import 'widgets/member_tile.dart';

/// `/family` — members, pending invitations, roles and membership status.
///
/// The invite action is rendered only for an actor Matrix A allows to invite
/// (owner, legal guardian, workspace admin). A direct 403 is still handled by
/// the sheet, so a stale client cannot turn the decision into a crash.
class FamilyPage extends ConsumerStatefulWidget {
  const FamilyPage({super.key});

  static const String routePath = '/family';

  @override
  ConsumerState<FamilyPage> createState() => _FamilyPageState();
}

class _FamilyPageState extends ConsumerState<FamilyPage> {
  static const EmptyState _emptyMembers = EmptyState(
    key: Key('family-empty-state'),
    icon: Icons.group_outlined,
    title: 'ยังไม่มีสมาชิก',
    message: 'เมื่อมีสมาชิกเข้าร่วมครอบครัว รายชื่อจะแสดงที่นี่',
  );

  @override
  Widget build(BuildContext context) {
    final AsyncValue<FamilyData> state = ref.watch(familyControllerProvider);
    final FamilyData? data = state.value;
    final bool showInviteAction =
        data != null && data.hasWorkspace && data.canInvite;

    return Scaffold(
      key: const Key('family-page'),
      floatingActionButton: showInviteAction
          ? FloatingActionButton.extended(
              key: const Key('family-invite-action'),
              onPressed: () => FamilyInviteSheet.show(context),
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              icon: const Icon(Icons.person_add_alt_1),
              label: const Text('เชิญสมาชิก'),
            )
          : null,
      body: state.when(
        loading: () => const LoadingState(key: Key('family-loading')),
        error: (Object error, StackTrace stackTrace) {
          if (error is ApiFailure && error.isPermissionDenied) {
            // Matrix A: viewers and caregivers cannot read the member list.
            // That is a deliberate decision, so this is an explanation state
            // with no retry button — retrying would just repeat the denial.
            return const EmptyState(
              key: Key('family-permission-state'),
              icon: Icons.lock_outline,
              title: 'ยังไม่สามารถดูรายชื่อสมาชิกได้',
              message:
                  'บทบาทผู้ชมหรือผู้ดูแลรายวันยังไม่ได้รับสิทธิ์ดูรายชื่อสมาชิก '
                  'ตามการตั้งค่าปัจจุบัน หากต้องการดูรายชื่อ โปรดขอสิทธิ์จากเจ้าของข้อมูล',
            );
          }

          return ErrorState(
            key: const Key('family-error'),
            message: error is ApiFailure
                ? error.message
                : ApiFailure.genericMessage,
            onRetry: () {
              ref.read(familyControllerProvider.notifier).retry();
            },
          );
        },
        data: (FamilyData value) {
          if (!value.hasWorkspace) {
            return const EmptyState(
              key: Key('family-no-workspace'),
              icon: Icons.family_restroom,
              title: 'ยังไม่มีครอบครัว',
              message: 'เมื่อมีครอบครัวหรือได้รับคำเชิญ สมาชิกจะแสดงที่นี่',
            );
          }

          return _body(value);
        },
      ),
    );
  }

  Widget _body(FamilyData value) {
    if (value.isEmpty && !value.hasInvitations) {
      return _emptyMembers;
    }

    final double bottomInset = value.canInvite
        ? AppSpacing.xxxl * 2
        : AppSpacing.lg;

    return CustomScrollView(
      key: const Key('family-list'),
      slivers: <Widget>[
        if (value.hasInvitations)
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.lg,
              0,
            ),
            sliver: SliverList(
              delegate: SliverChildListDelegate(<Widget>[
                Text('คำเชิญที่รอตอบรับ', style: AppType.sectionTitle),
                const SizedBox(height: AppSpacing.sm),
                for (final FamilyInvitation invitation in value.invitations)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.md),
                    child: InvitationTile(
                      key: Key('family-invitation-${invitation.id}'),
                      invitation: invitation,
                      onCopy: () => _copyInvitation(invitation),
                      onRevoke: () => _confirmRevoke(invitation),
                    ),
                  ),
                if (!value.isEmpty) ...<Widget>[
                  const SizedBox(height: AppSpacing.sm),
                  Text('สมาชิกในครอบครัว', style: AppType.sectionTitle),
                  const SizedBox(height: AppSpacing.sm),
                ],
              ]),
            ),
          ),
        if (value.isEmpty)
          const SliverFillRemaining(
            hasScrollBody: false,
            child: _emptyMembers,
          )
        else
          SliverPadding(
            padding: EdgeInsets.fromLTRB(
              AppSpacing.lg,
              value.hasInvitations ? 0 : AppSpacing.lg,
              AppSpacing.lg,
              bottomInset,
            ),
            sliver: SliverList.separated(
              itemCount: value.members.length,
              separatorBuilder: (BuildContext context, int index) =>
                  const SizedBox(height: AppSpacing.md),
              itemBuilder: (BuildContext context, int index) {
                final FamilyMembership member = value.members[index];

                return MemberTile(
                  key: Key('family-member-${member.id}'),
                  member: member,
                );
              },
            ),
          ),
      ],
    );
  }

  Future<void> _copyInvitation(FamilyInvitation invitation) async {
    final bool copied = await copyInvitationLink(invitation.link);

    if (!mounted) {
      return;
    }

    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            copied
                ? 'คัดลอกลิงก์แล้ว'
                : 'คัดลอกอัตโนมัติไม่สำเร็จ กรุณาคัดลอกลิงก์ด้วยตนเอง',
          ),
        ),
      );
  }

  Future<void> _confirmRevoke(FamilyInvitation invitation) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext dialogContext) {
        return AlertDialog(
          key: const Key('family-invitation-revoke-dialog'),
          title: const Text('ยกเลิกคำเชิญ?'),
          content: Text(
            'ต้องการยกเลิกคำเชิญสำหรับ ${invitation.email} หรือไม่ '
            'ลิงก์เชิญจะใช้ไม่ได้ทันที',
          ),
          actions: <Widget>[
            TextButton(
              key: const Key('family-invitation-revoke-cancel'),
              onPressed: () => Navigator.of(dialogContext).pop(false),
              child: const Text('ไม่ใช่'),
            ),
            FilledButton(
              key: const Key('family-invitation-revoke-confirm'),
              onPressed: () => Navigator.of(dialogContext).pop(true),
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.danger,
                foregroundColor: AppColors.textOnAccent,
              ),
              child: const Text('ยกเลิกคำเชิญ'),
            ),
          ],
        );
      },
    );

    if (confirmed != true || !mounted) {
      return;
    }

    final RevokeInvitationOutcome outcome = await ref
        .read(familyControllerProvider.notifier)
        .revokeInvitation(invitation);

    if (!mounted) {
      return;
    }

    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(
            outcome is RevokeInvitationFailure
                ? outcome.message
                : 'ยกเลิกคำเชิญแล้ว',
          ),
        ),
      );
  }
}
