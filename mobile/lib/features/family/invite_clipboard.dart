import 'package:flutter/services.dart';

/// Copies the one-time invitation link without adding a dependency.
///
/// Returns `false` when the platform clipboard is unavailable so the caller can
/// offer a manual fallback instead of failing silently. The link is never
/// logged or persisted.
Future<bool> copyInvitationLink(String link) async {
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
