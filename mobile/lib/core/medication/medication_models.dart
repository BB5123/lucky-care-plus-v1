import '../format/thai_date.dart';
import '../network/json_read.dart';

/// The two dose outcomes a person can report from the app.
///
/// `missed` exists on the server, but it is a system/back-office correction:
/// the daily loop only offers "taken" and "skipped".
enum MedicationDoseStatus { taken, skipped }

extension MedicationDoseStatusRules on MedicationDoseStatus {
  String get apiValue {
    return switch (this) {
      MedicationDoseStatus.taken => 'taken',
      MedicationDoseStatus.skipped => 'skipped',
    };
  }

  String get label {
    return switch (this) {
      MedicationDoseStatus.taken => 'ทานแล้ว',
      MedicationDoseStatus.skipped => 'ข้ามยา',
    };
  }
}

String medicationPlanStatusLabel(String status) {
  return switch (status) {
    'active' => 'กำลังใช้',
    'discontinued' => 'หยุดใช้แล้ว',
    _ => 'ไม่ทราบสถานะ',
  };
}

String medicationRecordStatusLabel(String status) {
  return switch (status) {
    'taken' => 'ทานแล้ว',
    'skipped' => 'ข้ามยา',
    'missed' => 'พลาด',
    _ => 'ไม่ทราบสถานะ',
  };
}

/// One medication plan from `GET .../medication-plans`.
class MedicationPlan {
  const MedicationPlan({
    required this.id,
    required this.name,
    required this.dosage,
    this.form = '',
    this.route = '',
    this.dosesPerDay = 0,
    this.scheduleTimes = const <String>[],
    this.startsOn,
    this.endsOn,
    this.prescribedBy,
    this.instructions,
    this.status = 'active',
    this.discontinuedReason,
    this.discontinuedAt,
    this.source = 'manual',
    this.version = 1,
    this.createdAt,
    this.updatedAt,
  });

  final String id;
  final String name;
  final String dosage;
  final String form;
  final String route;
  final int dosesPerDay;
  final List<String> scheduleTimes;
  final String? startsOn;
  final String? endsOn;
  final String? prescribedBy;
  final String? instructions;
  final String status;
  final String? discontinuedReason;
  final String? discontinuedAt;
  final String source;
  final int version;
  final String? createdAt;
  final String? updatedAt;

  bool get isActive => status == 'active';

  bool get isDiscontinued => status == 'discontinued';

  String get statusLabel => medicationPlanStatusLabel(status);

  String get doseLabel => 'วันละ $dosesPerDay ครั้ง';

  String get scheduleLabel => scheduleTimes.join(' · ');

  /// `10 ต.ค. 2569 – 20 ต.ค. 2569`, or an open-ended sentence.
  String get periodLabel {
    final String? startValue = startsOn;
    final String? endValue = endsOn;
    final String start = startValue == null ? '' : formatThaiDate(startValue);
    final String end = endValue == null ? '' : formatThaiDate(endValue);

    if (start.isEmpty && end.isEmpty) {
      return 'ไม่ระบุช่วงเวลา';
    }

    if (start.isEmpty) {
      return 'สิ้นสุด $end';
    }

    if (end.isEmpty) {
      return 'เริ่ม $start';
    }

    return '$start – $end';
  }

  factory MedicationPlan.fromJson(Map<String, dynamic> json) {
    return MedicationPlan(
      id: readString(json['id']),
      name: readString(json['name']),
      dosage: readString(json['dosage']),
      form: readString(json['form']),
      route: readString(json['route']),
      dosesPerDay: readInt(json['doses_per_day']),
      scheduleTimes: readList(json['schedule_times'])
          .whereType<String>()
          .where((String value) => value.isNotEmpty)
          .toList(growable: false),
      startsOn: readNullableString(json['starts_on']),
      endsOn: readNullableString(json['ends_on']),
      prescribedBy: readNullableString(json['prescribed_by']),
      instructions: readNullableString(json['instructions']),
      status: readString(json['status'], fallback: 'active'),
      discontinuedReason: readNullableString(json['discontinued_reason']),
      discontinuedAt: readNullableString(json['discontinued_at']),
      source: readString(json['source'], fallback: 'manual'),
      version: readInt(json['version'], fallback: 1),
      createdAt: readNullableString(json['created_at']),
      updatedAt: readNullableString(json['updated_at']),
    );
  }
}

/// One reported dose event from `POST .../medication-records`.
///
/// A `taken` row is self-reported; it is not clinical proof of ingestion.
class MedicationRecord {
  const MedicationRecord({
    required this.id,
    required this.planId,
    required this.status,
    this.scheduledFor,
    this.recordedAt,
    this.note,
    this.source = 'manual',
    this.version = 1,
  });

  final String id;
  final String planId;
  final String status;
  final String? scheduledFor;
  final String? recordedAt;
  final String? note;
  final String source;
  final int version;

  String get statusLabel => medicationRecordStatusLabel(status);

  factory MedicationRecord.fromJson(Map<String, dynamic> json) {
    return MedicationRecord(
      id: readString(json['id']),
      planId: readString(json['plan_id']),
      status: readString(json['status']),
      scheduledFor: readNullableString(json['scheduled_for']),
      recordedAt: readNullableString(json['recorded_at']),
      note: readNullableString(json['note']),
      source: readString(json['source'], fallback: 'manual'),
      version: readInt(json['version'], fallback: 1),
    );
  }
}

/// One day inside the server-provided adherence breakdown.
class AdherenceDay {
  const AdherenceDay({
    required this.date,
    this.expected = 0,
    this.taken = 0,
    this.skipped = 0,
    this.missed = 0,
    this.percent = 0,
  });

  final String date;
  final int expected;
  final int taken;
  final int skipped;
  final int missed;
  final double percent;

  /// Bars are a picture of the server's percentage, never a replacement for it.
  double get fraction => (percent / 100).clamp(0, 1).toDouble();

  factory AdherenceDay.fromJson(Map<String, dynamic> json) {
    return AdherenceDay(
      date: readString(json['date']),
      expected: readInt(json['expected']),
      taken: readInt(json['taken']),
      skipped: readInt(json['skipped']),
      missed: readInt(json['missed']),
      percent: readNullableDouble(json['percent']) ?? 0,
    );
  }
}

/// The server's aggregate for one plan over the rolling window.
///
/// Every number is rendered as received. The client never recomputes a
/// percentage: the window, the plan-active days and the attribution rules are
/// server decisions.
class MedicationAdherence {
  const MedicationAdherence({
    required this.planId,
    required this.name,
    this.expected = 0,
    this.taken = 0,
    this.skipped = 0,
    this.missed = 0,
    this.percent = 0,
    this.daily = const <AdherenceDay>[],
  });

  final String planId;
  final String name;
  final int expected;
  final int taken;
  final int skipped;
  final int missed;
  final double percent;
  final List<AdherenceDay> daily;

  String get percentLabel => '$percent%';

  factory MedicationAdherence.fromJson(Map<String, dynamic> json) {
    return MedicationAdherence(
      planId: readString(json['plan_id']),
      name: readString(json['name']),
      expected: readInt(json['expected']),
      taken: readInt(json['taken']),
      skipped: readInt(json['skipped']),
      missed: readInt(json['missed']),
      percent: readNullableDouble(json['percent']) ?? 0,
      daily: readList(json['daily'])
          .map((Object? entry) => AdherenceDay.fromJson(readMap(entry)))
          .toList(growable: false),
    );
  }
}

/// Active plans first; discontinued plans keep their server order after them.
List<MedicationPlan> orderMedicationPlans(List<MedicationPlan> items) {
  final List<MedicationPlan> active = <MedicationPlan>[];
  final List<MedicationPlan> discontinued = <MedicationPlan>[];

  for (final MedicationPlan plan in items) {
    (plan.isDiscontinued ? discontinued : active).add(plan);
  }

  return <MedicationPlan>[...active, ...discontinued];
}

/// What the medication segment needs once the context and both reads settle.
class MedicationData {
  MedicationData({
    required this.hasProfile,
    required List<MedicationPlan> plans,
    required List<MedicationAdherence> adherence,
  }) : plans = List<MedicationPlan>.unmodifiable(orderMedicationPlans(plans)),
       adherence = List<MedicationAdherence>.unmodifiable(adherence);

  const MedicationData._({
    required this.hasProfile,
    required this.plans,
    required this.adherence,
  });

  final bool hasProfile;
  final List<MedicationPlan> plans;
  final List<MedicationAdherence> adherence;

  bool get isEmpty => plans.isEmpty;

  MedicationAdherence? adherenceFor(String planId) {
    for (final MedicationAdherence entry in adherence) {
      if (entry.planId == planId) {
        return entry;
      }
    }

    return null;
  }

  static const MedicationData noProfile = MedicationData._(
    hasProfile: false,
    plans: <MedicationPlan>[],
    adherence: <MedicationAdherence>[],
  );
}

/// The body of `POST .../medication-plans`.
///
/// `doses_per_day` and `schedule_times` must agree: the server requires exactly
/// one valid `HH:MM` slot per daily dose. The review sheet checks that before
/// sending, but the server remains the judge.
class CreateMedicationPlanInput {
  const CreateMedicationPlanInput({
    required this.name,
    this.dosage,
    this.form = 'tablet',
    required this.dosesPerDay,
    required this.scheduleTimes,
    required this.startsOn,
    this.instructions,
  });

  final String name;
  final String? dosage;
  final String form;
  final int dosesPerDay;
  final List<String> scheduleTimes;

  /// `YYYY-MM-DD`.
  final String startsOn;
  final String? instructions;

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> json = <String, dynamic>{
      'name': name.trim(),
      'form': form,
      'doses_per_day': dosesPerDay,
      'schedule_times': scheduleTimes,
      'starts_on': startsOn,
    };
    final String? dosageValue = dosage?.trim();
    final String? instructionsValue = instructions?.trim();

    if (dosageValue != null && dosageValue.isNotEmpty) {
      json['dosage'] = dosageValue;
    }

    if (instructionsValue != null && instructionsValue.isNotEmpty) {
      json['instructions'] = instructionsValue;
    }

    return json;
  }
}

/// The medication forms accepted by the server (`MedicationPlanRules::FORMS`)
/// with the Thai label the review sheet shows.
const Map<String, String> medicationFormLabels = <String, String>{
  'tablet': 'เม็ด',
  'capsule': 'แคปซูล',
  'liquid': 'น้ำ',
  'injection': 'ฉีด',
  'patch': 'แผ่นแปะ',
  'inhaler': 'สูด',
  'other': 'อื่น ๆ',
};
