import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/events/presentation/screens/free_event_registration_screen.dart';

void main() {
  testWidgets('keeps the invitation field obscured and explains eligibility',
      (tester) async {
    await tester.pumpWidget(const ProviderScope(
      child: MaterialApp(home: FreeEventRegistrationScreen()),
    ));

    expect(find.text('Free event registration'), findsOneWidget);
    expect(find.text('Register for free'), findsOneWidget);
    expect(
      find.textContaining('invitation token is optional'),
      findsOneWidget,
    );
    expect(tester.widget<TextField>(find.byType(TextField).at(2)).obscureText,
        isTrue);
  });

  testWidgets('reuses the idempotency key after an ambiguous retry and shows capacity errors',
      (tester) async {
    final keys = <Object?>[];
    var requestCount = 0;
    final dio = Dio()
      ..interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
        keys.add(options.headers['Idempotency-Key']);
        requestCount++;
        if (requestCount == 1) {
          handler.reject(DioException(
            requestOptions: options,
            type: DioExceptionType.connectionError,
          ));
        } else {
          handler.reject(DioException(
            requestOptions: options,
            response: Response<Object?>(
              requestOptions: options,
              statusCode: 409,
              data: {'message': 'This allocation has reached its limit.'},
            ),
          ));
        }
      }));

    await tester.pumpWidget(ProviderScope(
      overrides: [freeEventRegistrationDioProvider.overrideWithValue(dio)],
      child: const MaterialApp(home: FreeEventRegistrationScreen()),
    ));
    await tester.enterText(find.byType(TextField).at(0), 'event-id');
    await tester.enterText(find.byType(TextField).at(1), 'free-category-id');
    await tester.tap(find.text('Register for free'));
    await tester.pumpAndSettle();
    expect(find.text('This invitation or free allocation is unavailable.'), findsOneWidget);

    await tester.tap(find.text('Register for free'));
    await tester.pumpAndSettle();
    expect(find.text('This allocation has reached its limit.'), findsOneWidget);
    expect(keys, hasLength(2));
    expect(keys.first, isNotNull);
    expect(keys.first, keys.last);
  });
}
