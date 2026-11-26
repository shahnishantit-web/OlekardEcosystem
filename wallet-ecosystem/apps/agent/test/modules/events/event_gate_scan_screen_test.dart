import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/modules/events/presentation/screens/event_gate_scan_screen.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';

class _AdmissionAdapter implements HttpClientAdapter {
  _AdmissionAdapter({this.loseFirstResponse = false});

  final bool loseFirstResponse;
  int calls = 0;
  RequestOptions? request;

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    if (options.path == '/v1/events/my-gate-assignments') {
      return ResponseBody.fromString(
        jsonEncode({
          'data': [
            {
              'event_id': 'event-1',
              'event_title': 'Demo Community Event',
              'event_starts_at': '2027-04-18T14:30:00+02:00',
              'gate_id': 'gate-1',
              'gate_name': 'Main Gate',
            }
          ]
        }),
        200,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType]
        },
      );
    }
    request = options;
    calls++;
    if (loseFirstResponse && calls == 1) {
      // Models a committed server operation whose response is lost in transit.
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionError,
        message: 'Test-only lost response after commit',
      );
    }
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'status': calls == 1 ? 'admitted' : 'already_admitted',
          'category': 'General',
        }
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType]
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<void> _openManualEntry(WidgetTester tester) async {
  await tester.pumpAndSettle();
  await tester.tap(find.text('Continue to scanner'));
  await tester.pumpAndSettle();
  await tester.ensureVisible(find.text('Enter ticket credential manually'));
  await tester.pumpAndSettle();
  await tester.tap(find.text('Enter ticket credential manually'));
  await tester.pumpAndSettle();
}

void _useTallerTestViewport(WidgetTester tester) {
  tester.view.physicalSize = const Size(800, 1200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

void main() {
  testWidgets(
      'offline admission is unavailable and fails closed in the gate interface',
      (tester) async {
    _useTallerTestViewport(tester);
    final adapter = _AdmissionAdapter();
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'))
      ..httpClientAdapter = adapter;
    await tester.pumpWidget(ProviderScope(
      overrides: [
        eventGateApiProvider.overrideWithValue(dio),
        connectivityProvider.overrideWith((ref) => false),
      ],
      child: const MaterialApp(home: EventGateScanScreen()),
    ));

    await _openManualEntry(tester);
    expect(find.text('You are offline — showing cached data'), findsOneWidget);
    final validate = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Validate credential'));
    expect(validate.onPressed, isNull);
    expect(find.textContaining('re-entry'), findsNothing);
    expect(find.textContaining('transfer'), findsNothing);
  });

  testWidgets(
      'connectivity loss disables validation and reconnection permits an online check',
      (tester) async {
    _useTallerTestViewport(tester);
    final adapter = _AdmissionAdapter();
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'))
      ..httpClientAdapter = adapter;
    final container = ProviderContainer(overrides: [
      eventGateApiProvider.overrideWithValue(dio),
      connectivityProvider.overrideWith((ref) => true),
    ]);
    addTearDown(container.dispose);

    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: const MaterialApp(home: EventGateScanScreen()),
    ));
    await _openManualEntry(tester);
    await tester.ensureVisible(find.byType(TextField));
    await tester.enterText(find.byType(TextField), 'C' * 43);

    container.read(connectivityProvider.notifier).state = false;
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Validate credential'))
          .onPressed,
      isNull,
    );
    await tester.ensureVisible(find.text('Validate credential'));
    await tester.tap(find.text('Validate credential'), warnIfMissed: false);
    await tester.pump();
    expect(adapter.calls, 0);
    expect(find.text('Admit'), findsNothing);

    container.read(connectivityProvider.notifier).state = true;
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Validate credential'));
    await tester.tap(find.text('Validate credential'));
    await tester.pumpAndSettle();
    expect(adapter.calls, 1);
    expect(find.text('Admit'), findsOneWidget);
  });

  testWidgets(
      'online validation sends credential in request body and displays only status/category',
      (tester) async {
    _useTallerTestViewport(tester);
    final adapter = _AdmissionAdapter();
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'))
      ..httpClientAdapter = adapter;
    final credential = 'A' * 43;
    await tester.pumpWidget(ProviderScope(
      overrides: [
        eventGateApiProvider.overrideWithValue(dio),
        connectivityProvider.overrideWith((ref) => true),
      ],
      child: const MaterialApp(home: EventGateScanScreen()),
    ));

    await _openManualEntry(tester);
    await tester.ensureVisible(find.byType(TextField));
    await tester.enterText(find.byType(TextField), credential);
    await tester.ensureVisible(find.text('Validate credential'));
    await tester.tap(find.text('Validate credential'));
    await tester.pumpAndSettle();

    expect(adapter.request, isNotNull);
    expect(adapter.request!.path, '/v1/events/event-1/gates/gate-1/validate');
    expect(adapter.request!.path, isNot(contains(credential)));
    expect(adapter.request!.data, {'credential': credential});
    expect(find.text('Admit'), findsOneWidget);
    expect(find.text('Ticket category: General'), findsOneWidget);
    expect(find.textContaining('payer'), findsNothing);
    expect(find.textContaining('payment'), findsNothing);
    expect(find.text(credential), findsNothing);
  });

  testWidgets('lost response is unconfirmed and retry checks server state',
      (tester) async {
    _useTallerTestViewport(tester);
    final adapter = _AdmissionAdapter(loseFirstResponse: true);
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'))
      ..httpClientAdapter = adapter;
    final credential = 'B' * 43;
    await tester.pumpWidget(ProviderScope(
      overrides: [
        eventGateApiProvider.overrideWithValue(dio),
        connectivityProvider.overrideWith((ref) => true),
      ],
      child: const MaterialApp(home: EventGateScanScreen()),
    ));

    await _openManualEntry(tester);
    await tester.ensureVisible(find.byType(TextField));
    await tester.enterText(find.byType(TextField), credential);
    await tester.ensureVisible(find.text('Validate credential'));
    await tester.tap(find.text('Validate credential'));
    await tester.pumpAndSettle();

    expect(find.text('Admission not confirmed'), findsOneWidget);
    expect(
      find.text(
          'The server did not confirm the result. Do not admit the attendee yet.'),
      findsOneWidget,
    );
    expect(find.text('Admit'), findsNothing);
    expect(find.text('Admission denied'), findsNothing);

    await tester.ensureVisible(find.text('Scan next ticket'));
    await tester.tap(find.text('Scan next ticket'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), credential);
    await tester.ensureVisible(find.text('Validate credential'));
    await tester.tap(find.text('Validate credential'));
    await tester.pumpAndSettle();

    expect(adapter.calls, 2);
    expect(find.text('Already admitted'), findsOneWidget);
    expect(find.text('Admission not confirmed'), findsNothing);
  });
}
