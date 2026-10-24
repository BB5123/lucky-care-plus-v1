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
