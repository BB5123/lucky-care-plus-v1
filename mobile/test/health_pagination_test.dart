import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:lucky_care/core/network/api_failure.dart';
import 'package:lucky_care/core/profiles/profile.models.dart';
import 'package:lucky_care/core/workspace/current_workspace.dart';
import 'package:lucky_care/design_system/app_theme.dart';
import 'package:lucky_care/features/health/health_controller.dart';
import 'package:lucky_care/features/health/health_models.dart';
import 'package:lucky_care/features/health/health_page.dart' as ui;
import 'package:lucky_care/features/health/health_repository.dart';

import 'support/test_doubles.dart';

const WorkspaceContext _context = WorkspaceContext(
  workspaceId: 'workspace-1',
  currentProfile: HealthProfileSummary(
    id: 'profile-1',
    personId: 'person-1',
    displayName: 'คุณพ่อ',
    relationship: 'self',
    kind: 'self',
  ),
);

class _FixedWorkspaceController extends CurrentWorkspaceController {
  @override
  Future<WorkspaceContext> build() async => _context;
}

/// Records every page request and lets each test decide the answer.
class _FakeHealthRepository extends HealthRepository {
  _FakeHealthRepository() : super(StubApiClient());

  final List<String?> requestedCursors = <String?>[];
  final List<int> requestedLimits = <int>[];
  int createCalls = 0;

  Future<HealthPage> Function(String? cursor, int limit)? onPage;
  Future<HealthMeasurement> Function(CreateMeasurementInput input)? onCreate;

  @override
  Future<HealthPage> page(
    WorkspaceContext context, {
    String? cursor,
    int limit = HealthRepository.defaultPageSize,
  }) {
    requestedCursors.add(cursor);
    requestedLimits.add(limit);

    final Future<HealthPage> Function(String?, int)? handler = onPage;

    if (handler == null) {
      return Future<HealthPage>.value(
        const HealthPage(items: <HealthMeasurement>[]),
      );
    }

    return handler(cursor, limit);
  }

  @override
  Future<HealthMeasurement> create(
    WorkspaceContext context,
    CreateMeasurementInput input,
  ) async {
    createCalls++;

    final Future<HealthMeasurement> Function(CreateMeasurementInput)? handler =
        onCreate;

    if (handler != null) {
      return handler(input);
    }

    return _measurement('created', '2026-10-25T12:00:00');
  }
}

/// [ApiClient] double that also keeps the query map (the shared
/// `StubApiClient` only records paths).
class _QueryRecordingApiClient extends StubApiClient {
  _QueryRecordingApiClient({super.response});

  final List<Map<String, dynamic>?> queries = <Map<String, dynamic>?>[];

  @override
  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) {
    queries.add(query);

    return super.get(path, query: query, headers: headers);
  }
}

HealthMeasurement _measurement(String id, String recordedAt) {
  return HealthMeasurement(
    id: id,
    type: 'weight',
    value: 60,
    unit: 'kg',
    recordedAt: recordedAt,
  );
}

ProviderContainer _container(_FakeHealthRepository repository) {
  final ProviderContainer container = ProviderContainer.test(
    overrides: <Override>[
      healthRepositoryProvider.overrideWithValue(repository),
      currentWorkspaceProvider.overrideWith(_FixedWorkspaceController.new),
    ],
  );
  final ProviderSubscription<AsyncValue<HealthData>> subscription = container
      .listen(healthControllerProvider, (_, _) {});

  addTearDown(subscription.close);

  return container;
}

Future<void> _pumpPage(WidgetTester tester, ProviderContainer container) async {
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp(theme: buildAppTheme(), home: const ui.HealthPage()),
    ),
  );
  await tester.pumpAndSettle();
}

List<String> _ids(HealthData data) {
  return data.measurements
      .map((HealthMeasurement measurement) => measurement.id)
      .toList(growable: false);
}

void main() {
  group('HPG history pagination', () {
    testWidgets('HPG-T01 the first page renders and stores next_cursor', (
      WidgetTester tester,
    ) async {
      final _FakeHealthRepository repository = _FakeHealthRepository()
        ..onPage = (String? cursor, int limit) async {
          return HealthPage(
            items: <HealthMeasurement>[
              _measurement('m1', '2026-10-24T09:00:00'),
              _measurement('m2', '2026-10-23T09:00:00'),
            ],
            nextCursor: 'cursor-2',
          );
        };
      final ProviderContainer container = _container(repository);

      await _pumpPage(tester, container);

      expect(find.byKey(const Key('measurement-m1')), findsOneWidget);
      expect(find.byKey(const Key('measurement-m2')), findsOneWidget);
      expect(repository.requestedCursors, <String?>[null]);

      final HealthData data = container
          .read(healthControllerProvider)
          .requireValue;

      expect(data.nextCursor, 'cursor-2');
      expect(data.hasMore, isTrue);
      expect(find.text('แสดง 2 รายการ — เลื่อนเพื่อดูเพิ่ม'), findsOneWidget);
    });

    test(
      'HPG-T02 loadMore requests the stored cursor and keeps earlier rows',
      () async {
        final _FakeHealthRepository repository = _FakeHealthRepository()
          ..onPage = (String? cursor, int limit) async {
            if (cursor == null) {
              return HealthPage(
                items: <HealthMeasurement>[
                  _measurement('m1', '2026-10-24T09:00:00'),
                  _measurement('m2', '2026-10-23T09:00:00'),
                ],
                nextCursor: 'cursor-2',
              );
            }

            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m3', '2026-10-22T09:00:00'),
              ],
            );
          };
        final ProviderContainer container = _container(repository);

        await container.read(healthControllerProvider.future);
        await container.read(healthControllerProvider.notifier).loadMore();

        final HealthData data = container
            .read(healthControllerProvider)
            .requireValue;

        expect(repository.requestedCursors, <String?>[null, 'cursor-2']);
        expect(_ids(data), <String>['m1', 'm2', 'm3']);
        expect(data.nextCursor, isNull);
        expect(data.isLoadingMore, isFalse);
        expect(data.loadMoreError, isNull);
      },
    );

    test('HPG-T03 a duplicate id across pages appears once', () async {
      final _FakeHealthRepository repository = _FakeHealthRepository()
        ..onPage = (String? cursor, int limit) async {
          if (cursor == null) {
            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m1', '2026-10-24T09:00:00'),
                _measurement('m2', '2026-10-23T09:00:00'),
              ],
              nextCursor: 'cursor-2',
            );
          }

          return HealthPage(
            items: <HealthMeasurement>[
              _measurement('m2', '2026-10-23T09:00:00'),
              _measurement('m3', '2026-10-22T09:00:00'),
            ],
          );
        };
      final ProviderContainer container = _container(repository);

      await container.read(healthControllerProvider.future);
      await container.read(healthControllerProvider.notifier).loadMore();

      final HealthData data = container
          .read(healthControllerProvider)
          .requireValue;

      expect(_ids(data), <String>['m1', 'm2', 'm3']);
      expect(data.measurements.length, 3);
      expect(
        data.measurements
            .where((HealthMeasurement measurement) => measurement.id == 'm2')
            .length,
        1,
      );
    });

    testWidgets(
      'HPG-T04 the last page shows the end label and offers no button',
      (WidgetTester tester) async {
        final _FakeHealthRepository repository = _FakeHealthRepository()
          ..onPage = (String? cursor, int limit) async {
            if (cursor == null) {
              return HealthPage(
                items: <HealthMeasurement>[
                  _measurement('m1', '2026-10-24T09:00:00'),
                  _measurement('m2', '2026-10-23T09:00:00'),
                ],
                nextCursor: 'cursor-2',
              );
            }

            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m3', '2026-10-22T09:00:00'),
              ],
            );
          };
        final ProviderContainer container = _container(repository);

        await _pumpPage(tester, container);
        await tester.tap(find.byKey(const Key('health-load-more')));
        await tester.pumpAndSettle();

        expect(find.text('แสดงครบทุกรายการแล้ว'), findsOneWidget);
        expect(find.byKey(const Key('health-history-end')), findsOneWidget);
        expect(find.byKey(const Key('health-load-more')), findsNothing);
        expect(find.byKey(const Key('measurement-m3')), findsOneWidget);
      },
    );

    testWidgets('HPG-T05 a failed loadMore keeps the rows and offers a retry', (
      WidgetTester tester,
    ) async {
      final _FakeHealthRepository repository = _FakeHealthRepository()
        ..onPage = (String? cursor, int limit) async {
          if (cursor == null) {
            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m1', '2026-10-24T09:00:00'),
                _measurement('m2', '2026-10-23T09:00:00'),
              ],
              nextCursor: 'cursor-2',
            );
          }

          throw const ApiFailure(
            status: 0,
            code: 'network',
            message: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่',
          );
        };
      final ProviderContainer container = _container(repository);

      await _pumpPage(tester, container);
      await tester.tap(find.byKey(const Key('health-load-more')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('measurement-m1')), findsOneWidget);
      expect(find.byKey(const Key('measurement-m2')), findsOneWidget);
      expect(find.byKey(const Key('health-load-more-error')), findsOneWidget);
      expect(find.byKey(const Key('health-load-more-retry')), findsOneWidget);
      expect(find.byKey(const Key('health-error')), findsNothing);

      final HealthData data = container
          .read(healthControllerProvider)
          .requireValue;

      expect(data.loadMoreError, isA<ApiFailure>());
      expect(data.isLoadingMore, isFalse);
      expect(data.nextCursor, 'cursor-2');
    });

    test(
      'HPG-T06 loadMore while one is in flight issues exactly one request',
      () async {
        final Completer<void> gate = Completer<void>();
        final _FakeHealthRepository repository = _FakeHealthRepository()
          ..onPage = (String? cursor, int limit) async {
            if (cursor == null) {
              return HealthPage(
                items: <HealthMeasurement>[
                  _measurement('m1', '2026-10-24T09:00:00'),
                ],
                nextCursor: 'cursor-2',
              );
            }

            await gate.future;

            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m2', '2026-10-23T09:00:00'),
              ],
            );
          };
        final ProviderContainer container = _container(repository);

        await container.read(healthControllerProvider.future);
        final HealthController controller = container.read(
          healthControllerProvider.notifier,
        );

        final Future<void> first = controller.loadMore();
        final Future<void> second = controller.loadMore();

        await Future<void>.delayed(Duration.zero);

        expect(repository.requestedCursors, <String?>[null, 'cursor-2']);

        gate.complete();
        await Future.wait(<Future<void>>[first, second]);

        expect(repository.requestedCursors, <String?>[null, 'cursor-2']);
        expect(
          _ids(container.read(healthControllerProvider).requireValue),
          <String>['m1', 'm2'],
        );
      },
    );

    test(
      'HPG-T07 the client never sends a limit above the server cap',
      () async {
        final _QueryRecordingApiClient api = _QueryRecordingApiClient(
          response: <String, dynamic>{
            'data': <Object?>[],
            'meta': <String, dynamic>{'next_cursor': null, 'limit': 100},
          },
        );
        final HealthRepository repository = HealthRepository(api);

        await repository.page(_context, limit: 500);
        await repository.page(_context, limit: 0);

        expect(api.queries, hasLength(2));
        expect(api.queries[0]?['limit'], HealthRepository.serverLimitCap);
        expect(api.queries[0]?['limit'], lessThanOrEqualTo(100));
        expect(api.queries[1]?['limit'], 1);
      },
    );

    test('HPG-T08 a response without meta is a typed contract error', () async {
      final HealthRepository repository = HealthRepository(
        StubApiClient(
          response: <String, dynamic>{
            'data': <Object?>[
              <String, dynamic>{
                'id': 'm1',
                'type': 'weight',
                'value': 60,
                'unit': 'kg',
              },
            ],
          },
        ),
      );

      final HealthPage page = await repository.page(_context);

      expect(page.nextCursor, isNull);
      expect(page.items, hasLength(1));
      expect(page.contractError, isA<PaginationContractException>());
    });

    test('HPG-T09 creating a measurement resets to the first page', () async {
      final _FakeHealthRepository repository = _FakeHealthRepository()
        ..onPage = (String? cursor, int limit) async {
          if (cursor == null) {
            return HealthPage(
              items: <HealthMeasurement>[
                _measurement('m1', '2026-10-24T09:00:00'),
                _measurement('m2', '2026-10-23T09:00:00'),
              ],
              nextCursor: 'cursor-2',
            );
          }

          return HealthPage(
            items: <HealthMeasurement>[
              _measurement('m3', '2026-10-22T09:00:00'),
            ],
          );
        }
        ..onCreate = (CreateMeasurementInput input) async {
          return _measurement('new', '2026-10-25T12:00:00');
        };
      final ProviderContainer container = _container(repository);

      await container.read(healthControllerProvider.future);
      await container.read(healthControllerProvider.notifier).loadMore();
      expect(
        _ids(container.read(healthControllerProvider).requireValue),
        <String>['m1', 'm2', 'm3'],
      );

      await container
          .read(healthControllerProvider.notifier)
          .record(const CreateMeasurementInput(type: 'weight', value: 70));

      final HealthData data = container
          .read(healthControllerProvider)
          .requireValue;

      // The third request is a fresh first page: no cursor.
      expect(repository.requestedCursors, <String?>[null, 'cursor-2', null]);
      expect(repository.createCalls, 1);
      expect(_ids(data), <String>['new', 'm1', 'm2']);
      expect(_ids(data), isNot(contains('m3')));
      expect(data.nextCursor, 'cursor-2');
    });
  });
}
