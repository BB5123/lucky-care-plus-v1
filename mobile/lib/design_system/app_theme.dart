import 'package:flutter/material.dart';

import 'tokens.dart';

/// Material 3 theme built only from [AppColors] / [AppType].
///
/// Keep this small on purpose: the design system owns the visual language, and
/// screens must not add component-level colour overrides.
ThemeData buildAppTheme() {
  final ColorScheme scheme = ColorScheme.fromSeed(
    seedColor: AppColors.info,
    brightness: Brightness.light,
  ).copyWith(
    primary: AppColors.info,
    onPrimary: AppColors.textOnAccent,
    surface: AppColors.surface,
    onSurface: AppColors.textPrimary,
    error: AppColors.danger,
    onError: AppColors.textOnAccent,
    outline: AppColors.border,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: AppColors.surface,
    disabledColor: AppColors.disabled,
    splashFactory: InkSparkle.splashFactory,
    textSelectionTheme: const TextSelectionThemeData(
      cursorColor: AppColors.info,
      selectionColor: AppColors.infoSurface,
    ),
  );
}
