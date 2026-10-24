import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/profiles/profiles_repository.dart';

import 'support/test_doubles.dart';

void main() {
  group('I2 profile list repository', () {
    test('I2-T01 maps the payload and prefers relationship == self', () async {
      final StubApiClient api = StubApiClient(
        response: <String, dynamic>{
          'data': <Object?>[
            <String, dynamic>{
              'id': 'profile-dependent',
              'person_id': 'person-1',
              'display_name': 'คุณแม่',
              'relationship': 'dependent',
              'kind': 'dependent',
            },
            <String, dynamic>{
              'id': 'profile-self',
              'person_id': 'person-2',
              'display_name': 'คุณพ่อ',
              'relationship': 'self',
              'kind': 'self',
            },
            // Malformed rows degrade to "ignored", never to an exception.
            <String, dynamic>{'id': ''},
            <String, dynamic>{'person_id': 'person-3'},
          ],
        },
      );
      final ProfilesRepository repository = ProfilesRepository(api);

      final ProfileSelection selection = await repository.load('workspace-1');

      expect(api.requestedPaths, <String>['/workspaces/workspace-1/profiles']);
      expect(
        selection.profiles.map((HealthProfileSummary profile) => profile.id),
        <String>['profile-dependent', 'profile-self'],
      );
      expect(selection.currentProfile?.id, 'profile-self');
      expect(selection.currentProfile?.displayName, 'คุณพ่อ');
      expect(selection.currentProfile?.isSelf, isTrue);
    });

    test('I2-T02 an empty list is a normal answer, not an error', () async {
      final ProfilesRepository repository = ProfilesRepository(
        StubApiClient(response: <String, dynamic>{'data': <Object?>[]}),
      );

      final ProfileSelection selection = await repository.load('workspace-1');

      expect(selection.profiles, isEmpty);
      expect(selection.currentProfile, isNull);
      expect(selection.hasProfile, isFalse);
    });
  });
}
