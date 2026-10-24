import '../network/json_read.dart';

/// One row of `GET /api/v1/workspaces/{workspace}/profiles`.
///
/// The projection is deliberately small: the picker only needs an id, the
/// person it belongs to, a display name and two server-decided labels. Every
/// field is read defensively because the payload is untrusted input.
class HealthProfileSummary {
  const HealthProfileSummary({
    required this.id,
    required this.personId,
    required this.displayName,
    required this.relationship,
    required this.kind,
  });

  final String id;
  final String personId;
  final String displayName;

  /// Actor-relative: `self`, `dependent` or `other`.
  final String relationship;

  /// Actor-independent: `self` when the person owns an account.
  final String kind;

  bool get isSelf => relationship == 'self';

  factory HealthProfileSummary.fromJson(Map<String, dynamic> json) {
    return HealthProfileSummary(
      id: readString(json['id']),
      personId: readString(json['person_id']),
      displayName: readString(json['display_name']),
      relationship: readString(json['relationship'], fallback: 'other'),
      kind: readString(json['kind'], fallback: 'dependent'),
    );
  }
}

/// The parsed list plus the entry the client should open first.
///
/// The server already orders the list (`self`, then dependents, then grants),
/// but the client still prefers an explicit `relationship == 'self'` entry so a
/// future ordering change cannot put a dependent in front of the actor's own
/// profile. An empty list is a normal answer, not a failure.
class ProfileSelection {
  const ProfileSelection({
    required this.profiles,
    required this.currentProfile,
  });

  final List<HealthProfileSummary> profiles;
  final HealthProfileSummary? currentProfile;

  bool get isEmpty => profiles.isEmpty;

  bool get hasProfile => currentProfile != null;

  static const ProfileSelection empty = ProfileSelection(
    profiles: <HealthProfileSummary>[],
    currentProfile: null,
  );
}

/// Narrows the `data` array of the profile list. Entries without an id are
/// discarded; nothing else throws, so a malformed row can never break a tab.
ProfileSelection parseProfileSelection(Object? data) {
  final List<HealthProfileSummary> profiles = readList(data)
      .map((Object? entry) => HealthProfileSummary.fromJson(readMap(entry)))
      .where((HealthProfileSummary profile) => profile.id.isNotEmpty)
      .toList(growable: false);

  if (profiles.isEmpty) {
    return ProfileSelection.empty;
  }

  for (final HealthProfileSummary profile in profiles) {
    if (profile.isSelf) {
      return ProfileSelection(profiles: profiles, currentProfile: profile);
    }
  }

  return ProfileSelection(profiles: profiles, currentProfile: profiles.first);
}
