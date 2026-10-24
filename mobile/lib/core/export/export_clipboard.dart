import 'package:flutter/services.dart';

/// Copies a short-lived export link without adding a dependency.
///
/// `url_launcher` is not part of this app's dependency set, so the card shows
/// the URL in a selectable field and offers this action. Returns `false` when
/// the platform clipboard is unavailable so the caller can fall back to manual
/// selection. The link is never logged or persisted.
Future<bool> copyExportLink(String link) async {
  if (link.isEmpty) {
    return false;
  }

  try {
    await Clipboard.setData(ClipboardData(text: link));

    return true;
  } on Object {
    return false;
  }
}
