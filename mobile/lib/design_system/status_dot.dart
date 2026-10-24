import 'package:flutter/material.dart';

import 'tokens.dart';

/// The health-status dot from the family switcher (blueprint §4, zone 1).
///
/// Colour is never the only signal: the semantic label carries the status for
/// screen readers, and red is reserved for "someone must act today".
class StatusDot extends StatelessWidget {
  const StatusDot({super.key, required this.status, this.size = AppSpacing.md});

  final String status;
  final double size;

  Color get _color {
    return switch (status) {
      'red' => AppColors.danger,
      'yellow' => AppColors.warning,
      _ => AppColors.success,
    };
  }

  String get _label {
    return switch (status) {
      'red' => 'ต้องดูแลทันที',
      'yellow' => 'มีงานค้าง',
      _ => 'ปกติ',
    };
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'สถานะ: $_label',
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: _color, shape: BoxShape.circle),
      ),
    );
  }
}
