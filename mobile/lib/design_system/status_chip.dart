import 'package:flutter/material.dart';

import 'tokens.dart';

/// Semantic tones for [StatusChip]. Colour is never the only signal: the chip
/// always carries a Thai label, so a screen reader and a colour-blind user get
/// the same information.
enum AppChipTone { neutral, success, warning, danger, info }

/// A small rounded label used for task tiers, appointment states and
/// membership states.
class StatusChip extends StatelessWidget {
  const StatusChip({
    super.key,
    required this.label,
    this.tone = AppChipTone.neutral,
  });

  final String label;
  final AppChipTone tone;

  Color get _background {
    return switch (tone) {
      AppChipTone.success => AppColors.successSurface,
      AppChipTone.warning => AppColors.warningSurface,
      AppChipTone.danger => AppColors.dangerSurface,
      AppChipTone.info => AppColors.infoSurface,
      AppChipTone.neutral => AppColors.surfaceRaised,
    };
  }

  Color get _foreground {
    return switch (tone) {
      AppChipTone.success => AppColors.success,
      AppChipTone.warning => AppColors.warning,
      AppChipTone.danger => AppColors.danger,
      AppChipTone.info => AppColors.info,
      AppChipTone.neutral => AppColors.textSecondary,
    };
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: AppSpacing.sm,
        vertical: AppSpacing.xs,
      ),
      decoration: BoxDecoration(
        color: _background,
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(
          color: tone == AppChipTone.neutral ? AppColors.border : _background,
        ),
      ),
      child: Text(
        label,
        style: AppType.caption.copyWith(
          color: _foreground,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}
