import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/network/api_failure.dart';
import '../../design_system/tokens.dart';
import 'dashboard_controller.dart';
import 'dashboard_models.dart';
import 'widgets/alerts_banner.dart';
import 'widgets/family_zone.dart';
import 'widgets/next_appointment_zone.dart';
import 'widgets/tasks_today_zone.dart';
import 'widgets/vitals_zone.dart';

/// `/` — the five dashboard zones in blueprint order.
///
/// Visibility is decided by the server (`visible` per zone). Hidden zones are
/// not built at all, so no value or id can leak through the widget tree.
class DashboardPage extends ConsumerWidget {
  const DashboardPage({super.key});

  static const String routePath = '/';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<DashboardData> state = ref.watch(
      dashboardControllerProvider,
    );

    return Scaffold(
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('LuckyCare+'),
        actions: <Widget>[
          IconButton(
            key: const Key('logout-button'),
            tooltip: 'ออกจากระบบ',
            icon: const Icon(Icons.logout),
            onPressed: () {
              ref.read(authControllerProvider.notifier).signOut();
            },
          ),
        ],
      ),
      body: state.when(
        loading: () => const _DashboardSkeleton(),
        error: (Object error, StackTrace stackTrace) {
          if (error is ApiFailure && error.isPermissionDenied) {
            // 403/404: the server will not reveal whether the data exists.
            // Show a calm empty state, never an error banner (D-T07).
            return const _PermissionEmpty();
          }

          return _DashboardError(
            message: error is ApiFailure
                ? error.message
                : ApiFailure.genericMessage,
            onRetry: () {
              ref.read(dashboardControllerProvider.notifier).retry();
            },
          );
        },
        data: (DashboardData data) => _DashboardBody(data: data),
      ),
    );
  }
}

class _DashboardBody extends StatelessWidget {
  const _DashboardBody({required this.data});

  final DashboardData data;

  @override
  Widget build(BuildContext context) {
    if (!data.hasWorkspace) {
      return const _EmptyState(
        key: Key('dashboard-no-workspace'),
        icon: Icons.home_outlined,
        title: 'ยังไม่มีครอบครัว',
        message: 'เมื่อมีครอบครัวหรือได้รับคำเชิญ ข้อมูลจะแสดงที่นี่',
      );
    }

    final DashboardPayload payload = data.payload;
    final List<Widget> zones = <Widget>[
      if (payload.family.visible)
        FamilyZoneWidget(
          key: FamilyZoneWidget.zoneKey,
          members: payload.family.members,
        ),
      if (payload.alerts.visible && payload.alerts.items.isNotEmpty)
        AlertsBanner(key: AlertsBanner.zoneKey, items: payload.alerts.items),
      if (payload.tasksToday.visible)
        TasksTodayZoneWidget(
          key: TasksTodayZoneWidget.zoneKey,
          zone: payload.tasksToday,
        ),
      if (payload.vitals.visible)
        VitalsZoneWidget(
          key: VitalsZoneWidget.zoneKey,
          cards: payload.vitals.cards,
        ),
      if (payload.nextAppointment.visible)
        NextAppointmentZoneWidget(
          key: NextAppointmentZoneWidget.zoneKey,
          zone: payload.nextAppointment,
        ),
    ];

    if (zones.isEmpty) {
      return const _EmptyState(
        key: Key('dashboard-empty-state'),
        icon: Icons.visibility_off_outlined,
        title: 'ยังไม่มีข้อมูลที่แสดงได้',
        message: 'เมื่อได้รับสิทธิ์จากเจ้าของข้อมูล พื้นที่นี้จะแสดงข้อมูลให้คุณ',
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.all(AppSpacing.lg),
      itemCount: zones.length,
      separatorBuilder: (BuildContext context, int index) =>
          const SizedBox(height: AppSpacing.lg),
      itemBuilder: (BuildContext context, int index) => zones[index],
    );
  }
}

class _DashboardSkeleton extends StatelessWidget {
  const _DashboardSkeleton();

  @override
  Widget build(BuildContext context) {
    return ListView(
      key: const Key('dashboard-loading'),
      padding: const EdgeInsets.all(AppSpacing.lg),
      children: const <Widget>[
        _SkeletonBlock(height: 96),
        SizedBox(height: AppSpacing.lg),
        _SkeletonBlock(height: 72),
        SizedBox(height: AppSpacing.lg),
        _SkeletonBlock(height: 140),
        SizedBox(height: AppSpacing.lg),
        _SkeletonBlock(height: 120),
      ],
    );
  }
}

class _SkeletonBlock extends StatelessWidget {
  const _SkeletonBlock({required this.height});

  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      decoration: BoxDecoration(
        color: AppColors.border,
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
    );
  }
}

class _DashboardError extends StatelessWidget {
  const _DashboardError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      key: const Key('dashboard-error'),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(
              Icons.cloud_off_outlined,
              size: AppSpacing.xxxl,
              color: AppColors.warning,
            ),
            const SizedBox(height: AppSpacing.md),
            const Text('โหลดข้อมูลไม่สำเร็จ', style: AppType.title),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              style: AppType.bodySecondary,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              onPressed: onRetry,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.info,
                foregroundColor: AppColors.textOnAccent,
                minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                textStyle: AppType.button,
              ),
              child: const Text('ลองอีกครั้ง'),
            ),
          ],
        ),
      ),
    );
  }
}

class _PermissionEmpty extends StatelessWidget {
  const _PermissionEmpty();

  @override
  Widget build(BuildContext context) {
    return const _EmptyState(
      key: Key('dashboard-empty-state'),
      icon: Icons.lock_outline,
      title: 'คุณยังไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
      message: 'หากต้องการดูข้อมูล กรุณาขอสิทธิ์จากเจ้าของข้อมูล',
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
  });

  final IconData icon;
  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Icon(icon, size: AppSpacing.xxxl, color: AppColors.textSecondary),
            const SizedBox(height: AppSpacing.md),
            Text(title, style: AppType.title, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              style: AppType.bodySecondary,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
