import 'package:flutter/material.dart';

import '../../../design_system/tokens.dart';
import '../../../design_system/zone_card.dart';
import '../dashboard_models.dart';

/// Zone 4 — vital signs as a 2-column grid: type, latest value, unit.
class VitalsZoneWidget extends StatelessWidget {
  const VitalsZoneWidget({super.key, required this.cards});

  static const Key zoneKey = Key('zone-vitals');

  final List<VitalCard> cards;

  @override
  Widget build(BuildContext context) {
    return ZoneCard(
      title: 'สัญญาณชีพ',
      icon: Icons.monitor_heart_outlined,
      child: cards.isEmpty
          ? const Text('ยังไม่มีค่าที่บันทึกไว้', style: AppType.bodySecondary)
          : GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: AppSpacing.md,
              crossAxisSpacing: AppSpacing.md,
              childAspectRatio: 1.5,
              children: cards
                  .map((VitalCard card) => _VitalTile(card: card))
                  .toList(growable: false),
            ),
    );
  }
}

class _VitalTile extends StatelessWidget {
  const _VitalTile({required this.card});

  final VitalCard card;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.infoSurface,
        borderRadius: BorderRadius.circular(AppRadius.md),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: <Widget>[
          Text(_typeLabel(card.type), style: AppType.caption),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(card.valueLabel, style: AppType.vitalValue),
          ),
          Text(card.unit, style: AppType.caption),
        ],
      ),
    );
  }

  String _typeLabel(String type) {
    return switch (type) {
      'blood_pressure' => 'ความดันโลหิต',
      'blood_glucose' => 'น้ำตาลในเลือด',
      'heart_rate' => 'ชีพจร',
      'weight' => 'น้ำหนัก',
      'spo2' => 'ออกซิเจนในเลือด',
      'temperature' => 'อุณหภูมิ',
      _ => 'ค่าสุขภาพ',
    };
  }
}
