/// Thai (Buddhist-era) date formatting without an `intl` dependency.
///
/// The server sends ISO-8601; the screen always shows Bangkok-local time in the
/// format the blueprint uses: `24 ต.ค. 2569 เวลา 09:00 น.`
String formatThaiDateTime(String isoValue) {
  final DateTime? parsed = DateTime.tryParse(isoValue);

  if (parsed == null) {
    return '';
  }

  final DateTime local = parsed.toLocal();
  const List<String> months = <String>[
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

  final int buddhistYear = local.year + 543;
  final String hour = local.hour.toString().padLeft(2, '0');
  final String minute = local.minute.toString().padLeft(2, '0');

  return '${local.day} ${months[local.month - 1]} $buddhistYear '
      'เวลา $hour:$minute น.';
}
