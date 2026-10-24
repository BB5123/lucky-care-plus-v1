import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../design_system/state_views.dart';
import '../../design_system/tokens.dart';
import 'family_controller.dart';
import 'family_models.dart';
import 'widgets/member_tile.dart';

/// `/family` — members, roles and membership status.
class FamilyPage extends ConsumerWidget {
  const FamilyPage({super.key});

  static const String routePath = '/family';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<FamilyData> state = ref.watch(familyControllerProvider);

    return Scaffold(
      key: const Key('family-page'),
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('ครอบครัว'),
      ),
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

          if (value.isEmpty) {
            return const EmptyState(
              key: Key('family-empty-state'),
              icon: Icons.group_outlined,
              title: 'ยังไม่มีสมาชิก',
              message: 'เมื่อมีสมาชิกเข้าร่วมครอบครัว รายชื่อจะแสดงที่นี่',
            );
          }

          return ListView.separated(
            key: const Key('family-list'),
            padding: const EdgeInsets.all(AppSpacing.lg),
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
          );
        },
      ),
    );
  }
}
