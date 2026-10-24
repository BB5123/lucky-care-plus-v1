import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';

/// Shared chrome for the login and register screens.
class AuthShell extends StatelessWidget {
  const AuthShell({
    super.key,
    required this.heading,
    required this.purpose,
    required this.form,
  });

  /// Thai screen heading, e.g. `เข้าสู่ระบบ`.
  final String heading;

  /// One line of Thai purpose copy shown under the product name.
  final String purpose;
  final Widget form;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.xl),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  const Text(
                    'LuckyCare+',
                    style: TextStyle(
                      fontSize: AppType.displaySize,
                      fontWeight: FontWeight.w700,
                      color: AppColors.info,
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Text(purpose, style: AppType.bodySecondary),
                  const SizedBox(height: AppSpacing.xl),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(AppSpacing.xl),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceRaised,
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                      border: Border.all(color: AppColors.border),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(heading, style: AppType.headline),
                        const SizedBox(height: AppSpacing.lg),
                        form,
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One input style for both auth screens so their errors line up exactly.
InputDecoration authInputDecoration({
  required String label,
  String? errorText,
  String? hintText,
}) {
  final OutlineInputBorder border = OutlineInputBorder(
    borderRadius: BorderRadius.circular(AppRadius.md),
    borderSide: const BorderSide(color: AppColors.border),
  );

  return InputDecoration(
    labelText: label,
    hintText: hintText,
    errorText: errorText,
    filled: true,
    fillColor: AppColors.surfaceRaised,
    contentPadding: const EdgeInsets.symmetric(
      horizontal: AppSpacing.md,
      vertical: AppSpacing.md,
    ),
    border: border,
    enabledBorder: border,
    focusedBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: const BorderSide(color: AppColors.info, width: 2),
    ),
    errorBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: const BorderSide(color: AppColors.danger),
    ),
    focusedErrorBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(AppRadius.md),
      borderSide: const BorderSide(color: AppColors.danger, width: 2),
    ),
  );
}

/// A safe Thai sentence from the API, or a generic one.
class AuthErrorBanner extends StatelessWidget {
  const AuthErrorBanner({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: AppSpacing.lg),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.dangerSurface,
        borderRadius: BorderRadius.circular(AppRadius.md),
        border: Border.all(color: AppColors.danger),
      ),
      child: Text(
        message,
        style: AppType.label.copyWith(color: AppColors.danger),
      ),
    );
  }
}

/// Full-width primary action used on both auth screens.
ButtonStyle authPrimaryButtonStyle() {
  return FilledButton.styleFrom(
    backgroundColor: AppColors.info,
    foregroundColor: AppColors.textOnAccent,
    disabledBackgroundColor: AppColors.disabled,
    disabledForegroundColor: AppColors.textOnAccent,
    minimumSize: const Size.fromHeight(AppSpacing.xxxl),
    textStyle: AppType.button,
  );
}
