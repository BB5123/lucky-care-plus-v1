import 'package:flutter/material.dart';

import 'tokens.dart';

/// Material 3 theme built only from [AppColors] / [AppType].
///
/// Keep this small on purpose: the design system owns the visual language, and
/// screens must not add component-level colour overrides.
///
/// With [elderMode] the Material text theme moves one step up the token scale,
/// so app bars, navigation labels and dialogs grow without any screen opting
/// in or hard-coding a size.
ThemeData buildAppTheme({bool elderMode = false}) {
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
    textTheme: elderMode ? _elderTextTheme : null,
  );
}

/// Material roles mapped onto the elder-mode token styles. Only the scale from
/// [AppType] is used; no size literal appears here.
const TextTheme _elderTextTheme = TextTheme(
  displayLarge: AppType.elderTitle,
  displayMedium: AppType.elderTitle,
  displaySmall: AppType.elderTitle,
  headlineLarge: AppType.elderTitle,
  headlineMedium: AppType.elderTitle,
  headlineSmall: AppType.elderTitle,
  titleLarge: AppType.elderTitle,
  titleMedium: AppType.elderTitle,
  titleSmall: AppType.elderTitle,
  bodyLarge: AppType.elderBody,
  bodyMedium: AppType.elderBody,
  bodySmall: AppType.elderBodySecondary,
  labelLarge: AppType.elderButton,
  labelMedium: AppType.elderBodySecondary,
  labelSmall: AppType.elderBodySecondary,
);
