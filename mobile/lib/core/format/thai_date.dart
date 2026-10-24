/// Thai (Buddhist-era) date formatting without an `intl` dependency.
///
/// The server sends ISO-8601; the screen always shows Bangkok-local time in the
/// format the blueprint uses: `24 ต.ค. 2569 เวลา 09:00 น.`
library;

const List<String> _thaiMonths = <String>[
  'ม.ค.',
  'ก.พ.',
  'มี.ค.',
  'เม.ย.',
  'พ.ค.',
  'มิ.ย.',
  'ก.ค.',
  'ส.ค.',
  'ก.ย.',
  'ต.ค.',
  'พ.ย.',
  'ธ.ค.',
];

class _ThaiDateParts {
  const _ThaiDateParts({
    required this.day,
    required this.month,
    required this.year,
    required this.hour,
    required this.minute,
  });

  final int day;
  final String month;
  final int year;
  final String hour;
  final String minute;
}

_ThaiDateParts? _parse(String isoValue) {
  final DateTime? parsed = DateTime.tryParse(isoValue);

  if (parsed == null) {
    return null;
  }

  final DateTime local = parsed.toLocal();

  return _ThaiDateParts(
    day: local.day,
    month: _thaiMonths[local.month - 1],
    year: local.year + 543,
    hour: local.hour.toString().padLeft(2, '0'),
    minute: local.minute.toString().padLeft(2, '0'),
  );
}

/// `24 ต.ค. 2569` — date-only values such as a plan's `starts_on`.
String formatThaiDate(String isoValue) {
  final _ThaiDateParts? parts = _parse(isoValue);

  if (parts == null) {
    return '';
  }

  return '${parts.day} ${parts.month} ${parts.year}';
}

/// `24 ต.ค. 2569 เวลา 09:00 น.`
String formatThaiDateTime(String isoValue) {
  final _ThaiDateParts? parts = _parse(isoValue);

  if (parts == null) {
    return '';
  }

  return '${parts.day} ${parts.month} ${parts.year} '
      'เวลา ${parts.hour}:${parts.minute} น.';
}
