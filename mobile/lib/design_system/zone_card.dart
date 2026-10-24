import 'package:flutter/material.dart';

import 'tokens.dart';

/// A titled surface used by every dashboard zone.
///
/// Zones stay visually identical so the five-zone order is the only hierarchy
/// the user has to learn.
class ZoneCard extends StatelessWidget {
  const ZoneCard({
    super.key,
    required this.title,
    required this.child,
    this.icon,
    this.trailing,
  });

  final String title;
  final Widget child;
  final IconData? icon;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final IconData? leadingIcon = icon;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(AppSpacing.lg),
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              if (leadingIcon != null) ...<Widget>[
                Icon(leadingIcon, size: AppSpacing.xl, color: AppColors.info),
                const SizedBox(width: AppSpacing.sm),
              ],
              Expanded(
                child: Text(title, style: AppType.sectionTitle),
              ),
              ?trailing,
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          child,
        ],
      ),
    );
  }
}
