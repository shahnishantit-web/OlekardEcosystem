import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:wallet_customer/modules/events/presentation/screens/customer_event_tickets_screen.dart';

class _TicketsAdapter implements HttpClientAdapter {
  _TicketsAdapter(
      {this.status = 'valid', this.credential = 'POC-DEMO-QR-NOT-VALID'});
  final String status;
  final String credential;
  final List<String> paths = [];
  Map<String, dynamic>? listPayload;

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    paths.add(options.path);
    final row = <String, dynamic>{
      'ticket_id': 'ticket-demo-1',
      'event_id': 'event-demo-1',
      'event_title': 'Community Music Day',
      'category': 'General admission',
      'status': status,
      'event_starts_at': '2027-04-18T14:30:00+02:00',
      'event_category': 'music',
      'image_url': null,
      'venue_name': 'Main Hall',
      'venue_city': 'Gaborone',
    };
    final payload = options.path.endsWith('/customer/invitations')
        ? {'data': <Object>[]}
        : options.path.endsWith('/tickets')
            ? {
                'data': [listPayload = row]
              }
            : {
                'data': {
                  ...row,
                  'credential': status == 'revoked' ? '' : credential,
                }
              };
    return ResponseBody.fromString(jsonEncode(payload), 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType]
    });
  }

  @override
  void close({bool force = false}) {}
}

Future<_TicketsAdapter> _showTickets(WidgetTester tester,
    {String status = 'valid',
    String credential = 'POC-DEMO-QR-NOT-VALID'}) async {
  final adapter = _TicketsAdapter(status: status, credential: credential);
  final dio = Dio(BaseOptions(baseUrl: 'http://demo.invalid'))
    ..httpClientAdapter = adapter;
  await tester.pumpWidget(ProviderScope(
      overrides: [eventTicketsApiProvider.overrideWithValue(dio)],
      child: const MaterialApp(home: CustomerEventTicketsScreen())));
  await tester.pumpAndSettle();
  return adapter;
}

void main() {
  testWidgets('ticket list and QR details remain usable with larger text',
      (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final dio = Dio(BaseOptions(baseUrl: 'http://demo.invalid'))
      ..httpClientAdapter = _TicketsAdapter();
    await tester.pumpWidget(ProviderScope(
      overrides: [eventTicketsApiProvider.overrideWithValue(dio)],
      child: MaterialApp(
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context)
              .copyWith(textScaler: const TextScaler.linear(1.35)),
          child: child!,
        ),
        home: const CustomerEventTicketsScreen(),
      ),
    ));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.drag(find.byType(ListView), const Offset(0, -600));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byTooltip('Show ticket QR'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Show ticket QR'));
    await tester.pumpAndSettle();
    expect(find.byType(QrImageView), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets(
      'ticket list shows event date and status; details render the unchanged QR payload without exposing it as text',
      (tester) async {
    final adapter = await _showTickets(tester);
    expect(find.text('Community Music Day'), findsOneWidget);
    expect(find.textContaining('Apr 18, 2027'), findsOneWidget);
    expect(find.text('POC-DEMO-QR-NOT-VALID'), findsNothing);
    expect(find.text('Main Hall \u00B7 Gaborone'), findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);
    expect(adapter.listPayload?.containsKey('credential'), isFalse);
    expect(adapter.paths,
        ['/v1/events/tickets', '/v1/events/customer/invitations']);

    await tester.tap(find.byTooltip('Show ticket QR'));
    await tester.pumpAndSettle();
    expect(adapter.paths, contains('/v1/events/tickets/ticket-demo-1'));
    expect(find.byType(QrImageView), findsOneWidget);
    expect(find.text('Show this QR code at the entrance.'), findsOneWidget);
    expect(find.text('POC-DEMO-QR-NOT-VALID'), findsNothing);
    expect(find.text('Ticket details'), findsOneWidget);
    expect(find.text('Main Hall \u00B7 Gaborone'), findsOneWidget);
    expect(find.byTooltip('Close ticket details'), findsOneWidget);
    await tester.tap(find.text('Close'));
    await tester.pumpAndSettle();
    expect(find.text('My Tickets'), findsOneWidget);
  });

  testWidgets(
      'cancelled ticket status is clear and detail does not show its QR',
      (tester) async {
    await _showTickets(tester, status: 'cancelled', credential: '');
    expect(find.text('Cancelled'), findsOneWidget);
    await tester.tap(find.text('Community Music Day'));
    await tester.pumpAndSettle();
    expect(find.text('This event was cancelled. This ticket cannot be used.'),
        findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);
    expect(find.text('Replace ticket code'), findsNothing);
  });

  testWidgets('revoked ticket is unavailable and does not show a QR',
      (tester) async {
    await _showTickets(tester, status: 'revoked', credential: '');
    expect(find.text('Revoked'), findsOneWidget);
    await tester.tap(find.text('Community Music Day'));
    await tester.pumpAndSettle();
    expect(find.text('This ticket is no longer valid.'), findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);
    expect(find.text('Replace ticket code'), findsNothing);
  });

  testWidgets('expired and used tickets show status without an admission QR',
      (tester) async {
    await _showTickets(tester, status: 'expired', credential: '');
    expect(find.text('Expired'), findsOneWidget);
    expect(find.byTooltip('Show ticket QR'), findsNothing);
    await tester.tap(find.text('Community Music Day'));
    await tester.pumpAndSettle();
    expect(find.text('This ticket has expired.'), findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);

    await tester.tap(find.text('Close'));
    await tester.pumpAndSettle();
    await tester.pumpWidget(const SizedBox.shrink());
    await _showTickets(tester, status: 'admitted', credential: '');
    expect(find.text('Used'), findsOneWidget);
    expect(find.byTooltip('Show ticket QR'), findsNothing);
  });
}
