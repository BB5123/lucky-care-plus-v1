import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../core/notifications/notification_models.dart';
import '../../design_system/state_views.dart';
import '../../design_system/tokens.dart';
import 'notifications_controller.dart';
import 'widgets/notification_settings.dart';
import 'widgets/notification_tile.dart';

enum NotificationSection { inbox, settings }

/// `/notifications` — the actor's inbox and channel preferences.
class NotificationsPage extends ConsumerStatefulWidget {
  const NotificationsPage({super.key});

  static const String routePath = '/notifications';

  @override
  ConsumerState<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends ConsumerState<NotificationsPage> {
  NotificationSection _section = NotificationSection.inbox;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      key: const Key('notifications-page'),
      appBar: AppBar(
        backgroundColor: AppColors.surface,
        surfaceTintColor: AppColors.surface,
        title: const Text('การแจ้งเตือน'),
      ),
      body: Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.lg,
              AppSpacing.sm,
            ),
            child: SegmentedButton<NotificationSection>(
              key: const Key('notification-section-segments'),
              segments: const <ButtonSegment<NotificationSection>>[
                ButtonSegment<NotificationSection>(
                  value: NotificationSection.inbox,
                  label: Text(
                    'กล่องแจ้งเตือน',
                    key: Key('notification-section-inbox'),
                  ),
                ),
                ButtonSegment<NotificationSection>(
                  value: NotificationSection.settings,
                  label: Text(
                    'การตั้งค่า',
                    key: Key('notification-section-settings'),
                  ),
                ),
              ],
              selected: <NotificationSection>{_section},
              onSelectionChanged: (Set<NotificationSection> selection) {
                setState(() {
                  _section = selection.first;
                });
              },
            ),
          ),
          Expanded(
            child: _section == NotificationSection.inbox
                ? const _InboxView()
                : const NotificationSettingsView(),
          ),
        ],
      ),
    );
  }
}

class _InboxView extends ConsumerWidget {
  const _InboxView();

  Future<void> _markRead(
    BuildContext context,
    WidgetRef ref,
    NotificationDelivery delivery,
  ) async {
    final bool saved = await ref
        .read(notificationDeliveriesControllerProvider.notifier)
        .markRead(delivery);

    if (!saved && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('ทำเครื่องหมายว่าอ่านแล้วไม่สำเร็จ')),
      );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final AsyncValue<List<NotificationDelivery>> state = ref.watch(
      notificationDeliveriesControllerProvider,
    );

    return state.when(
      loading: () => const LoadingState(key: Key('notification-inbox-loading')),
      error: (Object error, StackTrace stackTrace) {
        if (error is ApiFailure && error.isPermissionDenied) {
          return const EmptyState(
            key: Key('notification-inbox-permission-state'),
            icon: Icons.lock_outline,
            title: 'คุณยังไม่มีสิทธิ์ดูการแจ้งเตือน',
            message: 'การแจ้งเตือนจะแสดงเฉพาะบัญชีของเจ้าของเท่านั้น',
          );
        }

        return ErrorState(
          key: const Key('notification-inbox-error'),
          message: error is ApiFailure
              ? error.message
              : ApiFailure.genericMessage,
          onRetry: () {
            ref.read(notificationDeliveriesControllerProvider.notifier).retry();
          },
        );
      },
      data: (List<NotificationDelivery> deliveries) {
        if (deliveries.isEmpty) {
          return const EmptyState(
            key: Key('notification-inbox-empty'),
            icon: Icons.notifications_none,
            title: 'ยังไม่มีการแจ้งเตือน',
            message: 'เมื่อมีงานหรือนัดหมายที่ต้องแจ้งเตือน รายการจะแสดงที่นี่',
          );
        }

        final List<NotificationDelivery> ordered = orderNotifications(
          deliveries,
        );

        return ListView.separated(
          key: const Key('notification-inbox-list'),
          padding: const EdgeInsets.all(AppSpacing.lg),
          itemCount: ordered.length,
          separatorBuilder: (BuildContext context, int index) =>
              const SizedBox(height: AppSpacing.md),
          itemBuilder: (BuildContext context, int index) {
            final NotificationDelivery delivery = ordered[index];

            return NotificationTile(
              delivery: delivery,
              onMarkRead: delivery.isUnread
                  ? () => _markRead(context, ref, delivery)
                  : null,
            );
          },
        );
      },
    );
  }
}
