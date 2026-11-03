import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/events/presentation/screens/customer_event_tickets_screen.dart';

class _TicketsAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(
        jsonEncode({
          'data': [
            {
              'ticket_id': 'ticket-1',
              'event_id': 'event-1',
              'event_title': 'Test Event',
              'category': 'General',
              'status': 'admitted',
              'credential': 'A' * 43,
            },
          ],
        }),
        200,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType]
        },
      );

  @override
  void close({bool force = false}) {}
}

void main() {
  testWidgets(
      'shows purchaser credential and does not offer replacement after admission',
      (tester) async {
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'))
      ..httpClientAdapter = _TicketsAdapter();
    await tester.pumpWidget(ProviderScope(
      overrides: [eventTicketsApiProvider.overrideWithValue(dio)],
      child: const MaterialApp(home: CustomerEventTicketsScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Test Event'), findsOneWidget);
    expect(find.text('Entry recorded'), findsOneWidget);
    expect(find.text('Replace ticket credential'), findsNothing);
    expect(find.text('A' * 43), findsOneWidget);
  });
}
