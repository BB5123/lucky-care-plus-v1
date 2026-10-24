import 'package:flutter/material.dart';

import 'tokens.dart';

/// Skeleton loading state shared by the tab pages.
///
/// A skeleton is preferred over a centred spinner (`.ai/MOBILE_DESIGN.md` §9):
/// it keeps the layout stable so content does not jump when data arrives.
class LoadingState extends StatelessWidget {
  const LoadingState({super.key, this.rows = 3});

  final int rows;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.lg),
      children: <Widget>[
        for (int index = 0; index < rows; index++) ...<Widget>[
          if (index > 0) const SizedBox(height: AppSpacing.md),
          Container(
            height: index == 0 ? AppSpacing.xxxl * 2 : AppSpacing.xxxl * 1.5,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(AppRadius.lg),
            ),
          ),
        ],
      ],
    );
  }
}

/// A recoverable failure. [onRetry] is only wired for errors that can change
/// on a retry; a 403/404 decision renders [EmptyState] instead.
class ErrorState extends StatelessWidget {
  const ErrorState({
    super.key,
    required this.message,
    required this.onRetry,
    this.title = 'โหลดข้อมูลไม่สำเร็จ',
  });

  final String title;
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
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
            Text(title, style: AppType.title, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.sm),
            Text(
              message,
              style: AppType.bodySecondary,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              key: const Key('state-retry-button'),
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

/// Empty and permission-decision state. Deliberately has no action button: a
/// forbidden read is a decision, not a transient fault.
class EmptyState extends StatelessWidget {
  const EmptyState({
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
