import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/medication/medication_models.dart';
import '../../../core/network/api_failure.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import '../medication_controller.dart';
import 'adherence_card.dart';
import 'medication_plan_card.dart';
import 'record_dose_sheet.dart';

/// The ยา segment of the health tab: plans, the daily record action and the
/// server's seven-day adherence.
class MedicationView extends ConsumerWidget {
  const MedicationView({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<MedicationData> state = ref.watch(
      medicationControllerProvider,
    );

    return state.when(
      loading: () => const LoadingState(key: Key('medication-loading')),
      error: (Object error, StackTrace stackTrace) {
        if (error is ApiFailure && error.isPermissionDenied) {
          return const EmptyState(
            key: Key('medication-permission-state'),
            icon: Icons.lock_outline,
            title: 'คุณยังไม่มีสิทธิ์ดูข้อมูลยา',
            message: 'หากต้องการดูข้อมูลยา กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
          );
        }

        return ErrorState(
          key: const Key('medication-error'),
          message: error is ApiFailure
              ? error.message
              : ApiFailure.genericMessage,
          onRetry: () {
            ref.read(medicationControllerProvider.notifier).retry();
          },
        );
      },
      data: (MedicationData value) {
        if (!value.hasProfile) {
          return const EmptyState(
            key: Key('medication-no-profile'),
            icon: Icons.medication_outlined,
            title: 'ยังไม่มีโปรไฟล์สุขภาพ',
            message: 'เมื่อมีความเชื่อมโยงกับโปรไฟล์สุขภาพ แผนยาจะแสดงที่นี่',
          );
        }

        if (value.isEmpty) {
          return const EmptyState(
            key: Key('medication-empty-state'),
            icon: Icons.medication_outlined,
            title: 'ยังไม่มีแผนยา',
            message: 'เมื่อมีแผนยาที่บันทึกไว้ รายการยาและความสม่ำเสมอจะแสดงที่นี่',
          );
        }

        return ListView(
          key: const Key('medication-list'),
          padding: const EdgeInsets.all(AppSpacing.lg),
          children: <Widget>[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(AppSpacing.md),
              decoration: BoxDecoration(
                color: AppColors.infoSurface,
                borderRadius: BorderRadius.circular(AppRadius.md),
              ),
              child: const Text(
                'การบันทึกการทานยาเป็นการรายงานด้วยตนเอง ไม่ใช่หลักฐานทางการแพทย์',
                key: Key('medication-self-reported-note'),
                style: AppType.caption,
              ),
            ),
            const SizedBox(height: AppSpacing.lg),
            for (final MedicationPlan plan in value.plans) ...<Widget>[
              MedicationPlanCard(
                plan: plan,
                onRecord: plan.isActive
                    ? () => RecordDoseSheet.show(context, plan)
                    : null,
              ),
              const SizedBox(height: AppSpacing.md),
            ],
            if (value.adherence.isNotEmpty) ...<Widget>[
              const SizedBox(height: AppSpacing.sm),
              for (final MedicationAdherence adherence in value.adherence)
                ...<Widget>[
                  AdherenceCard(adherence: adherence),
                  const SizedBox(height: AppSpacing.md),
                ],
            ],
          ],
        );
      },
    );
  }
}
