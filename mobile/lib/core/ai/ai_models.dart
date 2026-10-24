import '../network/json_read.dart';

/// The `usage` block every AI response carries.
///
/// `remaining` is what the UI may show; `dailyLimit` is informational and may
/// be `0` when the server did not send it.
class AiUsage {
  const AiUsage({
    this.requestsToday = 0,
    this.remaining = 0,
    this.dailyLimit = 0,
  });

  final int requestsToday;
  final int remaining;
  final int dailyLimit;

  static const AiUsage empty = AiUsage();

  factory AiUsage.fromJson(Map<String, dynamic> json) {
    return AiUsage(
      requestsToday: readInt(json['requests_today']),
      remaining: readInt(json['remaining']),
      dailyLimit: readInt(json['daily_limit']),
    );
  }
}

/// The server said `AI_ENABLED=false` (503 `ai_disabled`).
///
/// This is a configuration state, not a failure: the screen shows one calm
/// explanation and keeps every manual path usable. It is deliberately its own
/// type so a widget cannot confuse it with a retryable error.
class AiUnavailable implements Exception {
  const AiUnavailable();

  @override
  String toString() => 'AiUnavailable';
}

/// The actor reached the daily AI limit (429 `ai_quota_exceeded`).
///
/// [resetAt] is when the counter rolls over. It is optional because the server
/// answers with `Retry-After` rather than a date; the card falls back to
/// tomorrow when the repository did not supply one.
class AiQuotaExceeded implements Exception {
  const AiQuotaExceeded({this.resetAt});

  final DateTime? resetAt;

  @override
  String toString() => 'AiQuotaExceeded';
}

/// The server answered `503 ai_vision_unavailable`.
///
/// The configured model cannot read images at all. Like [AiUnavailable] this is
/// a configuration state, not a transient error: the scan flow shows one calm
/// explanation and offers no draft, because there is nothing to review.
class AiVisionUnavailable implements Exception {
  const AiVisionUnavailable();

  @override
  String toString() => 'AiVisionUnavailable';
}

/// The corpus has no answer for this question (422 `knowledge_not_found`).
///
/// An empty result is not an error: the card explains it in muted copy and
/// keeps the input usable.
class AiNoMatch implements Exception {
  const AiNoMatch([this.message = defaultMessage]);

  static const String defaultMessage =
      'ไม่พบข้อมูลในฐานความรู้สำหรับคำถามนี้ ลองถามด้วยคำที่เฉพาะเจาะจงขึ้น '
      'หรือปรึกษาแพทย์';

  final String message;

  @override
  String toString() => 'AiNoMatch';
}

/// A draft trend summary returned by `POST .../ai/summaries`.
///
/// [recordRefs] is a **count**, not the references themselves: the ids are
/// dropped at parse time so no widget can render a record identifier or a raw
/// value by accident (N-02). [text] and [disclaimer] are the only prose kept.
class AiSummary {
  const AiSummary({
    required this.text,
    required this.disclaimer,
    required this.recordRefs,
    required this.model,
    this.usage = AiUsage.empty,
  });

  /// The generated Thai text.
  final String text;

  /// The server's "not a diagnosis" footnote — always rendered with [text].
  final String disclaimer;

  /// How many records the draft was grounded on.
  final int recordRefs;

  /// The model id, for the footer ("โมเดล …").
  final String model;

  final AiUsage usage;

  /// The server field is `summary`; the name is kept for the earlier card
  /// contract and for callers that read the raw envelope.
  String get summary => text;

  int get remaining => usage.remaining;

  /// A summary with no body or no disclaimer is not renderable.
  bool get isValid => text.trim().isNotEmpty && disclaimer.trim().isNotEmpty;

  factory AiSummary.fromJson(Map<String, dynamic> json) {
    final String text = readString(json['text']);
    final String legacy = readString(json['summary']);

    return AiSummary(
      text: text.trim().isEmpty ? legacy : text,
      disclaimer: readString(json['disclaimer']),
      recordRefs: readList(json['record_refs']).length,
      model: readString(json['model']),
      usage: AiUsage.fromJson(readMap(json['usage'])),
    );
  }
}

/// One cited document behind a knowledge answer.
///
/// `id` is kept for tracking only; the card never renders it. [reviewStatus]
/// drives the "ยังไม่ผ่านการตรวจ" hint.
class KnowledgeSource {
  const KnowledgeSource({
    required this.label,
    required this.id,
    required this.title,
    required this.publisher,
    required this.url,
    this.publishedAt = '',
    this.fetchedAt = '',
    this.reviewStatus = '',
  });

  /// `[A]`, `[B]`, ... — the marker the answer text cites.
  final String label;

  /// Corpus document id. Never displayed.
  final String id;

  final String title;
  final String publisher;
  final String url;

  /// Publication date when the corpus has one, else empty.
  final String publishedAt;

  /// Date the corpus snapshot was fetched.
  final String fetchedAt;

  /// `reviewed` or `unreviewed`.
  final String reviewStatus;

  /// Anything that is not an explicit `reviewed` is treated as unreviewed, so
  /// a missing field never silently looks approved.
  bool get isUnreviewed => reviewStatus != 'reviewed';

  /// The date the source list shows: publication first, then the fetch date.
  String get dateValue => publishedAt.isNotEmpty ? publishedAt : fetchedAt;

  factory KnowledgeSource.fromJson(Map<String, dynamic> json) {
    return KnowledgeSource(
      label: readString(json['label']),
      id: readString(json['id']),
      title: readString(json['title']),
      publisher: readString(json['publisher']),
      url: readString(json['url']),
      publishedAt: readString(json['published_at']),
      fetchedAt: readString(json['fetched_at']),
      reviewStatus: readString(json['review_status']),
    );
  }
}

/// A cited answer from the local health-knowledge corpus.
class KnowledgeAnswer {
  const KnowledgeAnswer({
    required this.answer,
    required this.sources,
    required this.disclaimer,
    required this.model,
    this.usage = AiUsage.empty,
  });

  /// The answer text, including inline `[A]` markers the model added.
  final String answer;

  final List<KnowledgeSource> sources;

  /// The server's "general knowledge, not a diagnosis" footnote.
  final String disclaimer;

  final String model;

  final AiUsage usage;

  int get remaining => usage.remaining;

  bool get isValid => answer.trim().isNotEmpty && sources.isNotEmpty;

  factory KnowledgeAnswer.fromJson(Map<String, dynamic> json) {
    return KnowledgeAnswer(
      answer: readString(json['answer']),
      sources: readList(json['sources'])
          .map((Object? entry) => KnowledgeSource.fromJson(readMap(entry)))
          .where((KnowledgeSource source) => source.title.isNotEmpty)
          .toList(growable: false),
      disclaimer: readString(json['disclaimer']),
      model: readString(json['model']),
      usage: AiUsage.fromJson(readMap(json['usage'])),
    );
  }
}

/// The two vision-extraction kinds `POST .../ai/extractions` accepts.
enum AiExtractionKind { medicationLabel, deviceReading }

extension AiExtractionKindRules on AiExtractionKind {
  String get apiValue {
    return switch (this) {
      AiExtractionKind.medicationLabel => 'medication_label',
      AiExtractionKind.deviceReading => 'device_reading',
    };
  }
}

/// A vision-extraction **draft** from `POST .../ai/extractions` (TASK P-3).
///
/// A draft is the only source of the review sheet's fields; the AI never writes
/// a health row (AIG-007). Two deliberate limits protect the screen:
///
///  - `document_id` is not kept. A widget cannot render an identifier it never
///    receives (N-02), and the draft does not need one after extraction.
///  - [fields] is filtered to the closed per-kind schema. A provider answer (or
///    a malformed payload) that carries an extra key, such as a raw reading or
///    a patient name, cannot reach a form by accident.
class AiExtractionDraft {
  const AiExtractionDraft({
    required this.kind,
    required this.fields,
    required this.warnings,
    required this.confidence,
    required this.model,
    this.requiresConfirmation = true,
  });

  /// `medication_label` or `device_reading`.
  final String kind;

  /// Only the keys in the per-kind schema, with nulls removed.
  final Map<String, Object?> fields;

  /// Server warning codes. Rendered through [aiExtractionWarningMessage];
  /// the raw code is never shown.
  final List<String> warnings;

  final double confidence;
  final String model;

  /// The server always answers `true`; a draft without it is not writable.
  final bool requiresConfirmation;

  static const String medicationKind = 'medication_label';
  static const String deviceKind = 'device_reading';

  static const List<String> medicationFieldNames = <String>[
    'name',
    'dosage',
    'doses_per_day',
    'schedule_times',
    'instructions',
  ];

  static const List<String> deviceFieldNames = <String>[
    'type',
    'value',
    'secondary_value',
    'unit',
    'measured_at',
  ];

  bool get isMedication => kind == medicationKind;

  bool get isDeviceReading => kind == deviceKind;

  /// A success without a confirmation flag is not a draft this screen may
  /// present as writable: fail closed instead of guessing (F-09).
  bool get isValid => requiresConfirmation && (isMedication || isDeviceReading);

  String stringValue(String key) {
    final Object? value = fields[key];

    return value is String ? value.trim() : '';
  }

  double? numberValue(String key) {
    final Object? value = fields[key];

    if (value is num) {
      return value.toDouble();
    }

    if (value is String) {
      return double.tryParse(value.trim());
    }

    return null;
  }

  int? intValue(String key) {
    final double? value = numberValue(key);

    if (value == null || value != value.roundToDouble()) {
      return null;
    }

    return value.toInt();
  }

  List<String> stringListValue(String key) {
    final Object? value = fields[key];

    if (value is! List) {
      return const <String>[];
    }

    return value
        .whereType<String>()
        .map((String item) => item.trim())
        .where((String item) => item.isNotEmpty)
        .toList(growable: false);
  }

  factory AiExtractionDraft.fromJson(Map<String, dynamic> json) {
    final String kind = readString(json['kind']);
    final Map<String, dynamic> rawFields = readMap(json['fields']);
    final List<String> allowed;
    if (kind == medicationKind) {
      allowed = medicationFieldNames;
    } else if (kind == deviceKind) {
      allowed = deviceFieldNames;
    } else {
      allowed = const <String>[];
    }
    final Map<String, Object?> fields = <String, Object?>{};

    for (final String name in allowed) {
      final Object? value = rawFields[name];

      if (value != null) {
        fields[name] = value;
      }
    }

    final List<String> warnings = readList(json['warnings'])
        .whereType<String>()
        .map((String warning) => warning.trim())
        .where((String warning) => warning.isNotEmpty)
        .toSet()
        .toList(growable: false);

    return AiExtractionDraft(
      kind: kind,
      fields: Map<String, Object?>.unmodifiable(fields),
      warnings: warnings,
      confidence: (readNullableDouble(json['confidence']) ?? 0)
          .clamp(0, 1)
          .toDouble(),
      model: readString(json['model']),
      requiresConfirmation: json['requires_confirmation'] == true,
    );
  }
}

/// A safe Thai sentence for one server warning code.
///
/// The code itself is an implementation detail; an unknown code falls back to a
/// generic "some fields could not be read" line so a new server warning can
/// never surface as an English identifier in the UI.
String aiExtractionWarningMessage(String code) {
  return switch (code) {
    'name_invalid' => 'ชื่อยาบนซองอ่านไม่ชัด จึงยังไม่ถูกกรอกไว้ กรุณาตรวจสอบ',
    'dosage_invalid' => 'ขนาดยาบนซองอ่านไม่ชัด จึงยังไม่ถูกกรอกไว้',
    'doses_per_day_out_of_range' =>
      'จำนวนครั้งต่อวันอยู่นอกช่วงที่บันทึกได้ จึงไม่ถูกกรอกไว้',
    'schedule_times_invalid' =>
      'เวลาทานยาอ่านไม่ครบหรือไม่ถูกต้อง จึงไม่ถูกกรอกไว้',
    'instructions_invalid' =>
      'คำแนะนำบนฉลากอ่านไม่ชัด จึงยังไม่ถูกกรอกไว้',
    'type_not_supported' =>
      'ชนิดค่าที่อ่านได้ไม่รองรับในแอป จึงให้เลือกชนิดเอง',
    'value_out_of_range' =>
      'ค่าที่อ่านได้อยู่นอกช่วงที่บันทึกได้ จึงไม่ถูกกรอกไว้',
    'secondary_value_invalid' =>
      'ค่าตัวล่างอ่านไม่ชัด จึงไม่ถูกกรอกไว้',
    'unit_mismatch' =>
      'หน่วยบนหน้าจอไม่ตรงกับชนิดค่าที่เลือก กรุณาตรวจสอบ',
    'measured_at_invalid' =>
      'เวลาที่วัดอ่านไม่ชัด จึงไม่ถูกกรอกไว้',
    'injection_neutralised' =>
      'พบข้อความที่ดูเหมือนคำสั่งในภาพ จึงถูกตัดออกเพื่อความปลอดภัย',
    _ => 'ข้อมูลบางส่วนอ่านไม่ชัด จึงไม่ถูกกรอกไว้ กรุณาตรวจสอบ',
  };
}
