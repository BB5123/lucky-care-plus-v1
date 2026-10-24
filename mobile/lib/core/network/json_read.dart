/// Small, total readers for untrusted JSON.
///
/// The API is the authority; the client still treats every field as optional.
/// A malformed or missing value degrades to a safe default instead of throwing,
/// which is what "fail closed" means for a renderer (F-09).
library;

String readString(Object? value, {String fallback = ''}) {
  return value is String ? value : fallback;
}

String? readNullableString(Object? value) {
  if (value is! String) {
    return null;
  }

  return value.isEmpty ? null : value;
}

int readInt(Object? value, {int fallback = 0}) {
  if (value is int) {
    return value;
  }

  if (value is num) {
    return value.toInt();
  }

  if (value is String) {
    return int.tryParse(value) ?? fallback;
  }

  return fallback;
}

double? readNullableDouble(Object? value) {
  if (value is num) {
    return value.toDouble();
  }

  if (value is String) {
    return double.tryParse(value);
  }

  return null;
}

bool readBool(Object? value) {
  return value == true;
}

List<Object?> readList(Object? value) {
  return value is List ? value : const <Object?>[];
}

Map<String, dynamic> readMap(Object? value) {
  if (value is Map<String, dynamic>) {
    return value;
  }

  if (value is Map) {
    return Map<String, dynamic>.from(value);
  }

  return const <String, dynamic>{};
}
