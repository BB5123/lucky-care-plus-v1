import '../network/json_read.dart';

/// AI summary returned by `POST .../ai/summaries`.
///
/// Every field is optional on the wire; [isValid] refuses a body that would
/// render as an empty card. No health value is cached here.
class AiSummary {
  const AiSummary({
    required this.summary,
    required this.disclaimer,
    required this.recordRefs,
    required this.requestsToday,
    required this.remaining,
    required this.model,
  });

  final String summary;
  final String disclaimer;
  final int recordRefs;
  final int requestsToday;
  final int remaining;
  final String model;

  bool get isValid =>
      summary.trim().isNotEmpty && disclaimer.trim().isNotEmpty;

  factory AiSummary.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> usage = readMap(json['usage']);

    return AiSummary(
      summary: readString(json['summary']),
      disclaimer: readString(json['disclaimer']),
      recordRefs: readList(json['record_refs']).length,
      requestsToday: readInt(usage['requests_today']),
      remaining: readInt(usage['remaining']),
      model: readString(json['model']),
    );
  }
}

/// One cited source of a knowledge answer.
class KnowledgeSource {
  const KnowledgeSource({
    required this.label,
    required this.title,
    required this.publisher,
    required this.url,
  });

  final String label;
  final String title;
  final String publisher;
  final String url;

  factory KnowledgeSource.fromJson(Map<String, dynamic> json) {
    return KnowledgeSource(
      label: readString(json['label']),
      title: readString(json['title']),
      publisher: readString(json['publisher']),
      url: readString(json['url']),
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
    required this.requestsToday,
    required this.remaining,
  });

  final String answer;
  final List<KnowledgeSource> sources;
  final String disclaimer;
  final String model;
  final int requestsToday;
  final int remaining;

  bool get isValid => answer.trim().isNotEmpty && sources.isNotEmpty;

  factory KnowledgeAnswer.fromJson(Map<String, dynamic> json) {
    final Map<String, dynamic> usage = readMap(json['usage']);

    return KnowledgeAnswer(
      answer: readString(json['answer']),
      sources: readList(json['sources'])
          .map((Object? entry) => KnowledgeSource.fromJson(readMap(entry)))
          .where((KnowledgeSource source) => source.title.isNotEmpty)
          .toList(growable: false),
      disclaimer: readString(json['disclaimer']),
      model: readString(json['model']),
      requestsToday: readInt(usage['requests_today']),
      remaining: readInt(usage['remaining']),
    );
  }
}
