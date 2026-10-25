import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/workspace/retention_status.dart';

void main() {
  test('RET-M01 parses the five declared states', () {
    expect(
      RetentionStatus.fromJson(<String, Object?>{'retention_state': 'active'})
          .state,
      RetentionState.active,
    );
    expect(
      RetentionStatus.fromJson(<String, Object?>{
        'retention_state': 'read_only',
      }).state,
      RetentionState.readOnly,
    );
    expect(
      RetentionStatus.fromJson(<String, Object?>{'retention_state': 'archived'})
          .state,
      RetentionState.archived,
    );
    expect(
      RetentionStatus.fromJson(<String, Object?>{
        'retention_state': 'scheduled_deletion',
      }).state,
      RetentionState.scheduledDeletion,
    );
    expect(
      RetentionStatus.fromJson(<String, Object?>{'retention_state': 'deleted'})
          .state,
      RetentionState.deleted,
    );
  });

  test('RET-M02 an unknown or absent state is treated as no status', () {
    expect(
      RetentionStatus.fromJson(<String, Object?>{'retention_state': 'mystery'})
          .isRestricted,
      isFalse,
    );
    expect(RetentionStatus.fromJson(null).isRestricted, isFalse);
    expect(RetentionStatus.fromJson(<String, Object?>{}).isRestricted, isFalse);
    expect(RetentionStatus.none.bannerTitle, isNull);
  });

  test('RET-M03 a restricted state has Thai copy and keeps the expiry', () {
    final RetentionStatus status = RetentionStatus.fromJson(<String, Object?>{
      'retention_state': 'read_only',
      'retention_expires_at': '2026-11-24T00:00:00+00:00',
    });

    expect(status.isRestricted, isTrue);
    expect(status.bannerTitle, contains('อ่านอย่างเดียว'));
    expect(status.bannerDetail, isNotEmpty);
    expect(status.expiresAt, '2026-11-24T00:00:00+00:00');

    final RetentionStatus archived = RetentionStatus.fromJson(<String, Object?>{
      'retention_state': 'archived',
      'retention_expires_at': 42,
    });

    expect(archived.bannerTitle, contains('เก็บถาวร'));
    expect(archived.expiresAt, isNull);
  });
}
