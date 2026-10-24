import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../design_system/state_views.dart';
import '../../design_system/tokens.dart';
import 'appointment_models.dart';
import 'appointments_controller.dart';
import 'widgets/appointment_tile.dart';

/// `/appointments` — read-only list, upcoming first.
class AppointmentsPage extends ConsumerWidget {
  const AppointmentsPage({super.key});

  static const String routePath = '/appointments';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<AppointmentsData> state = ref.watch(
      appointmentsControllerProvider,
    );

    return Scaffold(
      key: const Key('appointments-page'),
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('นัดหมาย'),
      ),
      body: state.when(
        loading: () => const LoadingState(key: Key('appointments-loading')),
        error: (Object error, StackTrace stackTrace) {
          if (error is ApiFailure && error.isPermissionDenied) {
            return const EmptyState(
              key: Key('appointments-permission-state'),
              icon: Icons.lock_outline,
              title: 'คุณยังไม่มีสิทธิ์ดูนัดหมาย',
              message: 'หากต้องการดูนัดหมาย กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
            );
          }

          return ErrorState(
            key: const Key('appointments-error'),
            message: error is ApiFailure
                ? error.message
                : ApiFailure.genericMessage,
            onRetry: () {
              ref.read(appointmentsControllerProvider.notifier).retry();
            },
          );
        },
        data: (AppointmentsData value) {
          if (!value.hasProfile) {
            return const EmptyState(
              key: Key('appointments-no-profile'),
              icon: Icons.event_busy_outlined,
              title: 'ยังไม่มีโปรไฟล์สุขภาพ',
              message:
                  'เมื่อมีความเชื่อมโยงกับโปรไฟล์สุขภาพ นัดหมายจะแสดงที่นี่',
            );
          }

          return Column(
            children: <Widget>[
              const Padding(
                padding: EdgeInsets.fromLTRB(
                  AppSpacing.lg,
                  AppSpacing.lg,
                  AppSpacing.lg,
                  AppSpacing.sm,
                ),
                child: Text(
                  'การสร้างหรือแก้ไขนัดหมายจะมาในรุ่นถัดไป',
                  key: Key('appointments-read-only-note'),
                  style: AppType.caption,
                ),
              ),
              Expanded(
                child: value.isEmpty
                    ? const EmptyState(
                        key: Key('appointments-empty-state'),
                        icon: Icons.event_available,
                        title: 'ยังไม่มีนัดหมาย',
                        message:
                            'เมื่อมีนัดหมายที่บันทึกไว้ รายการจะแสดงที่นี่',
                      )
                    : ListView.separated(
                        key: const Key('appointments-list'),
                        padding: const EdgeInsets.all(AppSpacing.lg),
                        itemCount: value.appointments.length,
                        separatorBuilder: (BuildContext context, int index) =>
                            const SizedBox(height: AppSpacing.md),
                        itemBuilder: (BuildContext context, int index) {
                          final Appointment appointment =
                              value.appointments[index];

                          return AppointmentTile(
                            key: Key('appointment-${appointment.id}'),
                            appointment: appointment,
                          );
                        },
                      ),
              ),
            ],
          );
        },
      ),
    );
  }
}
