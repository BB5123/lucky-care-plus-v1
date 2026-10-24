import 'package:flutter/material.dart';

import '../../../core/notifications/notification_models.dart';
import '../../../design_system/status_chip.dart';
import '../../../design_system/tokens.dart';

/// One notification row: local Thai copy, channel, relative time and the
/// explicit mark-read action. A failed delivery gets a muted chip and stays in
/// the list like any other row.
class NotificationTile extends StatelessWidget {
  const NotificationTile({
    super.key,
    required this.delivery,
    this.onMarkRead,
  });

  final NotificationDelivery delivery;
  final VoidCallback? onMarkRead;

  @override
  Widget build(BuildContext context) {
    final bool unread = delivery.isUnread;

    return InkWell(
      onTap: unread ? onMarkRead : null,
      borderRadius: BorderRadius.circular(AppRadius.lg),
      child: Container(
        key: Key('notification-${delivery.id}'),
        width: double.infinity,
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: BoxDecoration(
          color: unread ? AppColors.infoSurface : AppColors.surfaceRaised,
          borderRadius: BorderRadius.circular(AppRadius.lg),
          border: Border.all(
            color: unread ? AppColors.info : AppColors.border,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Expanded(
                  child: Text(
                    delivery.copy,
                    key: Key('notification-copy-${delivery.id}'),
                    style: AppType.label,
                  ),
                ),
                if (unread) ...<Widget>[
                  const SizedBox(width: AppSpacing.sm),
                  Container(
                    key: Key('notification-unread-${delivery.id}'),
                    width: AppSpacing.sm,
                    height: AppSpacing.sm,
                    decoration: const BoxDecoration(
                      color: AppColors.info,
                      shape: BoxShape.circle,
                    ),
                  ),
                ],
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              '${delivery.channelLabel} · ${delivery.relativeTime}',
              key: Key('notification-meta-${delivery.id}'),
              style: AppType.caption,
            ),
            if (delivery.isFailed) ...<Widget>[
              const SizedBox(height: AppSpacing.sm),
              StatusChip(
                key: Key('notification-failed-${delivery.id}'),
                label: 'ส่งไม่สำเร็จ',
                tone: AppChipTone.neutral,
              ),
            ],
            if (unread && onMarkRead != null) ...<Widget>[
              const SizedBox(height: AppSpacing.xs),
              TextButton(
                key: Key('notification-read-${delivery.id}'),
                onPressed: onMarkRead,
                child: const Text('ทำเครื่องหมายว่าอ่านแล้ว'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
