import '../../core/format/thai_date.dart';
import '../../core/network/json_read.dart';

/// The three measurement kinds this slice can record.
enum MeasurementKind { bloodPressure, bloodGlucose, weight }

extension MeasurementKindRules on MeasurementKind {
  String get apiValue {
    return switch (this) {
      MeasurementKind.bloodPressure => 'blood_pressure',
      MeasurementKind.bloodGlucose => 'blood_glucose',
      MeasurementKind.weight => 'weight',
    };
  }

  String get label {
    return switch (this) {
      MeasurementKind.bloodPressure => 'ความดันโลหิต',
      MeasurementKind.bloodGlucose => 'น้ำตาลในเลือด',
      MeasurementKind.weight => 'น้ำหนัก',
    };
  }

  /// Short label for the segmented control.
  String get shortLabel {
    return switch (this) {
      MeasurementKind.bloodPressure => 'ความดัน',
      MeasurementKind.bloodGlucose => 'น้ำตาล',
      MeasurementKind.weight => 'น้ำหนัก',
    };
  }

  String get unit {
    return switch (this) {
      MeasurementKind.bloodPressure => 'mmHg',
      MeasurementKind.bloodGlucose => 'mg/dL',
      MeasurementKind.weight => 'kg',
    };
  }

  /// Mirrors `MeasurementType` on the server. The server stays the judge; the
  /// client only blocks obviously wrong input before sending it.
  double get minimum {
    return switch (this) {
      MeasurementKind.bloodPressure => 50,
      MeasurementKind.bloodGlucose => 20,
      MeasurementKind.weight => 1,
    };
  }

  double get maximum {
    return switch (this) {
      MeasurementKind.bloodPressure => 300,
      MeasurementKind.bloodGlucose => 800,
      MeasurementKind.weight => 400,
    };
  }

  bool get requiresSecondary => this == MeasurementKind.bloodPressure;

  bool get requiresContext => this == MeasurementKind.bloodGlucose;
}

/// Glucose context values accepted by the server.
enum GlucoseContext { fasting, postprandial, random }

extension GlucoseContextRules on GlucoseContext {
  String get apiValue {
    return switch (this) {
      GlucoseContext.fasting => 'fasting',
      GlucoseContext.postprandial => 'postprandial',
      GlucoseContext.random => 'random',
    };
  }

  String get label {
    return switch (this) {
      GlucoseContext.fasting => 'หลังอดอาหาร',
      GlucoseContext.postprandial => 'หลังอาหาร',
      GlucoseContext.random => 'สุ่ม',
    };
  }
}

String measurementTypeLabel(String type) {
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

String measurementUnitForType(String type) {
  return switch (type) {
    'blood_pressure' => 'mmHg',
    'blood_glucose' => 'mg/dL',
    'heart_rate' => 'bpm',
    'weight' => 'kg',
    'spo2' => '%',
    'temperature' => '°C',
    _ => '',
  };
}

String measurementContextLabel(String? context) {
  return switch (context) {
    'fasting' => 'หลังอดอาหาร',
    'postprandial' => 'หลังอาหาร',
    'random' => 'สุ่ม',
    'morning' => 'เช้า',
    'evening' => 'เย็น',
    _ => '',
  };
}

/// One reading from `GET .../measurements`.
class HealthMeasurement {
  const HealthMeasurement({
    required this.id,
    required this.type,
    this.value,
    this.secondaryValue,
    this.unit = '',
    this.context,
    this.recordedAt,
    this.version = 1,
  });

  final String id;
  final String type;
  final double? value;
  final double? secondaryValue;
  final String unit;
  final String? context;
  final String? recordedAt;
  final int version;

  String get typeLabel => measurementTypeLabel(type);

  String get unitLabel {
    if (unit.isNotEmpty) {
      return unit;
    }

    return measurementUnitForType(type);
  }

  String get contextLabel => measurementContextLabel(context);

  String get recordedAtLabel {
    final String? value = recordedAt;

    return value == null ? '' : formatThaiDateTime(value);
  }

  /// `120/80` for blood pressure, `64.5` for a single value.
  String get valueLabel {
    final double? primary = value;

    if (primary == null) {
      return '—';
    }

    final double? secondary = secondaryValue;

    if (secondary == null) {
      return _formatNumber(primary);
    }

    return '${_formatNumber(primary)}/${_formatNumber(secondary)}';
  }

  DateTime? get recordedAtDate {
    final String? value = recordedAt;

    return value == null ? null : DateTime.tryParse(value);
  }

  factory HealthMeasurement.fromJson(Map<String, dynamic> json) {
    return HealthMeasurement(
      id: readString(json['id']),
      type: readString(json['type']),
      value: readNullableDouble(json['value']),
      secondaryValue: readNullableDouble(json['secondary_value']),
      unit: readString(json['unit']),
      context: readNullableString(json['context']),
      recordedAt: readNullableString(json['recorded_at']),
      version: readInt(json['version'], fallback: 1),
    );
  }

  static String _formatNumber(double value) {
    if (value == value.roundToDouble()) {
      return value.toInt().toString();
    }

    return value.toString();
  }
}

/// What the health tab needs once the workspace context and the list resolve.
class HealthData {
  HealthData({
    required this.hasProfile,
    required List<HealthMeasurement> measurements,
  }) : measurements = List<HealthMeasurement>.unmodifiable(
         _newestFirst(measurements),
       );

  const HealthData._({required this.hasProfile, required this.measurements});

  final bool hasProfile;
  final List<HealthMeasurement> measurements;

  bool get isEmpty => measurements.isEmpty;

  static const HealthData noProfile = HealthData._(
    hasProfile: false,
    measurements: <HealthMeasurement>[],
  );

  static List<HealthMeasurement> _newestFirst(List<HealthMeasurement> items) {
    final List<HealthMeasurement> sorted = List<HealthMeasurement>.of(items);

    sorted.sort((HealthMeasurement left, HealthMeasurement right) {
      final DateTime? leftAt = left.recordedAtDate;
      final DateTime? rightAt = right.recordedAtDate;

      if (leftAt == null && rightAt == null) {
        return 0;
      }

      if (leftAt == null) {
        return 1;
      }

      if (rightAt == null) {
        return -1;
      }

      return rightAt.compareTo(leftAt);
    });

    return sorted;
  }
}

/// The body of `POST .../measurements`.
class CreateMeasurementInput {
  const CreateMeasurementInput({
    required this.type,
    required this.value,
    this.secondaryValue,
    this.context,
  });

  final String type;
  final double value;
  final double? secondaryValue;
  final String? context;

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> json = <String, dynamic>{
      'type': type,
      'value': value,
    };
    final double? secondary = secondaryValue;
    final String? glucoseContext = context;

    if (secondary != null) {
      json['secondary_value'] = secondary;
    }

    if (glucoseContext != null && glucoseContext.isNotEmpty) {
      json['context'] = glucoseContext;
    }

    return json;
  }
}
