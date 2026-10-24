import 'package:flutter/material.dart';

/// Design tokens for LuckyCare+ (`.ai/MOBILE_DESIGN.md` §8).
///
/// Colour literals live only in this file. Widgets reference the semantic
/// names, so an elder-mode contrast pass is a one-file change and no screen
/// can accidentally invent a shade that is not part of the palette.
abstract final class AppColors {
  /// Completed work, stable readings.
  static const Color success = Color(0xFF2E7D32);

  /// Pending work, slightly out-of-range readings.
  static const Color warning = Color(0xFFB26A00);

  /// Act now, SOS, break-glass. Never for a marginally high number.
  static const Color danger = Color(0xFFC62828);

  /// General information and links.
  static const Color info = Color(0xFF1565C0);

  /// Page background. A warm off-white lowers stress compared with pure white.
  static const Color surface = Color(0xFFFFFBF5);

  /// Cards and inputs on top of [surface].
  static const Color surfaceRaised = Color(0xFFFFFFFF);

  static const Color border = Color(0xFFE3DED4);
  static const Color textPrimary = Color(0xFF1F2933);
  static const Color textSecondary = Color(0xFF5B6672);
  static const Color textOnAccent = Color(0xFFFFFFFF);
  static const Color disabled = Color(0xFFB9B2A7);

  static const Color successSurface = Color(0xFFE8F5E9);
  static const Color warningSurface = Color(0xFFFFF4E0);
  static const Color dangerSurface = Color(0xFFFDECEA);
  static const Color infoSurface = Color(0xFFE8F1FB);
}

/// Spacing scale from the blueprint: 4 / 8 / 12 / 16 / 24 / 32 / 48.
///
/// A literal `SizedBox(height: 12)` is a review defect; use `AppSpacing.md`.
abstract final class AppSpacing {
  static const double xs = 4;
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double xl = 24;
  static const double xxl = 32;
  static const double xxxl = 48;
}

abstract final class AppRadius {
  static const double sm = 8;
  static const double md = 12;
  static const double lg = 16;
  static const double pill = 999;
}

/// Type scale from §8.2: 12 / 14 / 16 / 20 / 24 / 32.
///
/// Sizes outside this scale must not be used for text.
abstract final class AppType {
  static const double captionSize = 12;
  static const double labelSize = 14;
  static const double bodySize = 16;
  static const double titleSize = 20;
  static const double headlineSize = 24;
  static const double displaySize = 32;

  static const TextStyle display = TextStyle(
    fontSize: displaySize,
    fontWeight: FontWeight.w700,
    height: 1.2,
    color: AppColors.textPrimary,
  );

  static const TextStyle headline = TextStyle(
    fontSize: headlineSize,
    fontWeight: FontWeight.w700,
    height: 1.3,
    color: AppColors.textPrimary,
  );

  static const TextStyle title = TextStyle(
    fontSize: titleSize,
    fontWeight: FontWeight.w600,
    height: 1.35,
    color: AppColors.textPrimary,
  );

  static const TextStyle body = TextStyle(
    fontSize: bodySize,
    height: 1.5,
    color: AppColors.textPrimary,
  );

  static const TextStyle bodySecondary = TextStyle(
    fontSize: bodySize,
    height: 1.5,
    color: AppColors.textSecondary,
  );

  static const TextStyle label = TextStyle(
    fontSize: labelSize,
    height: 1.4,
    color: AppColors.textPrimary,
  );

  /// Zone heading. Deliberately smaller than [title] so data stays dominant.
  static const TextStyle sectionTitle = TextStyle(
    fontSize: labelSize,
    fontWeight: FontWeight.w700,
    height: 1.4,
    color: AppColors.textPrimary,
  );

  static const TextStyle caption = TextStyle(
    fontSize: captionSize,
    height: 1.4,
    color: AppColors.textSecondary,
  );

  /// Numeric values use tabular figures so cards line up across readings.
  static const TextStyle vitalValue = TextStyle(
    fontSize: headlineSize,
    fontWeight: FontWeight.w700,
    height: 1.2,
    color: AppColors.textPrimary,
    fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
  );

  static const TextStyle button = TextStyle(
    fontSize: bodySize,
    fontWeight: FontWeight.w600,
    height: 1.2,
  );
}
