import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';
import '../dashboard_models.dart';
import '../elder_focus.dart';

/// Elder mode (`.ai/MOBILE_DESIGN.md` §6): one focus card, one full-width
/// primary action, type at 20sp or above, and no charts, tables or member
/// chips. The caller mounts it instead of the five-zone stack.
class ElderFocusCard extends StatelessWidget {
  const ElderFocusCard({
    super.key,
    required this.payload,
    required this.onAction,
  });

  static const Key cardKey = Key('elder-focus-card');
  static const Key primaryActionKey = Key('elder-primary-action');
  static const Key familyStatusKey = Key('elder-family-status');

  final DashboardPayload payload;
  final ValueChanged<ElderFocusAction> onAction;

  @override
  Widget build(BuildContext context) {
    final ElderFocusAction action = selectElderFocusAction(payload);
    final String? familyLine = elderFamilyStatusLine(payload.family);

    return Container(
      key: cardKey,
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
          if (familyLine != null) ...<Widget>[
            Text(
              familyLine,
              key: familyStatusKey,
              style: AppType.elderBodySecondary,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: AppSpacing.lg),
            const Divider(color: AppColors.border, height: 1),
            const SizedBox(height: AppSpacing.lg),
          ],
          const Text(
            'สิ่งที่ต้องทำตอนนี้',
            style: AppType.elderBody,
          ),
          const SizedBox(height: AppSpacing.md),
          Text(action.title, style: AppType.elderTitle),
          const SizedBox(height: AppSpacing.md),
          Text(action.detail, style: AppType.elderBodySecondary),
          const SizedBox(height: AppSpacing.xl),
          SizedBox(
            width: double.infinity,
            height: AppSpacing.xxxl,
            child: FilledButton(
              key: primaryActionKey,
              onPressed: () => onAction(action),
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.info,
                foregroundColor: AppColors.textOnAccent,
                minimumSize: const Size.fromHeight(AppSpacing.xxxl),
                textStyle: AppType.elderButton,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(AppRadius.md),
                ),
              ),
              child: Text(action.label),
            ),
          ),
        ],
      ),
    );
  }
}
