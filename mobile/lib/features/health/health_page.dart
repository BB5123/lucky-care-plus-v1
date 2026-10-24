import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_failure.dart';
import '../../../design_system/state_views.dart';
import '../../../design_system/tokens.dart';
import 'health_controller.dart';
import 'health_models.dart';
import 'widgets/measurement_tile.dart';
import 'widgets/record_measurement_sheet.dart';

/// `/health` — readings for the current profile, newest first.
class HealthPage extends ConsumerWidget {
  const HealthPage({super.key});

  static const String routePath = '/health';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<HealthData> state = ref.watch(healthControllerProvider);
    final HealthData? data = state.value;

    return Scaffold(
      key: const Key('health-page'),
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('สุขภาพ'),
      ),
      floatingActionButton: data != null && data.hasProfile
          ? FloatingActionButton.extended(
              key: const Key('health-record-button'),
              onPressed: () => RecordMeasurementSheet.show(context),
              backgroundColor: AppColors.info,
              foregroundColor: AppColors.textOnAccent,
              icon: const Icon(Icons.add),
              label: const Text('บันทึกค่า', style: AppType.button),
            )
          : null,
      body: state.when(
        loading: () => const LoadingState(key: Key('health-loading')),
        error: (Object error, StackTrace stackTrace) {
          if (error is ApiFailure && error.isPermissionDenied) {
            return const EmptyState(
              key: Key('health-permission-state'),
              icon: Icons.lock_outline,
              title: 'คุณยังไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
              message: 'หากต้องการดูค่าสุขภาพ กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
            );
          }

          return ErrorState(
            key: const Key('health-error'),
            message: error is ApiFailure
                ? error.message
                : ApiFailure.genericMessage,
            onRetry: () {
              ref.read(healthControllerProvider.notifier).retry();
            },
          );
        },
        data: (HealthData value) {
          if (!value.hasProfile) {
            return const EmptyState(
              key: Key('health-no-profile'),
              icon: Icons.monitor_heart_outlined,
              title: 'ยังไม่มีโปรไฟล์สุขภาพ',
              message: 'เมื่อมีความเชื่อมโยงกับโปรไฟล์สุขภาพ ค่าที่บันทึกไว้จะแสดงที่นี่',
            );
          }

          if (value.isEmpty) {
            return const EmptyState(
              key: Key('health-empty-state'),
              icon: Icons.monitor_heart_outlined,
              title: 'ยังไม่มีค่าที่บันทึกไว้',
              message:
                  'กด “บันทึกค่า” เพื่อเริ่มบันทึกความดัน น้ำตาล หรือน้ำหนัก',
            );
          }

          return ListView.separated(
            key: const Key('health-list'),
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.xxxl * 2,
            ),
            itemCount: value.measurements.length,
            separatorBuilder: (BuildContext context, int index) =>
                const SizedBox(height: AppSpacing.md),
            itemBuilder: (BuildContext context, int index) {
              final HealthMeasurement measurement = value.measurements[index];

              return MeasurementTile(
                key: Key('measurement-${measurement.id}'),
                measurement: measurement,
              );
            },
          );
        },
      ),
    );
  }
}
