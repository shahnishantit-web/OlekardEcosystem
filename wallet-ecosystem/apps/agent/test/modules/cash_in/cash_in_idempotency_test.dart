import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';
import 'package:wallet_agent/modules/cash_in/data/repositories/cash_in_repository_impl.dart';

class MockDio extends Mock implements Dio {}

void main() {
  late MockDio dio;
  late CashInRepositoryImpl repository;

  final responseBody = jsonDecode(
    '{"success":true,"data":{"id":"txn-demo-1","reference":"CI-DEMO-001",'
    '"amount":50000,"currency_code":"BWP","status":"completed",'
    '"created_at":"2026-10-05T09:30:00Z"}}',
  ) as Map<String, dynamic>;

  setUp(() {
    dio = MockDio();
    repository = CashInRepositoryImpl(dio: dio, agentId: 'agent-demo-1');
    registerFallbackValue(RequestOptions(path: ''));
    registerFallbackValue(Options());
  });

  const request = CashInRequestDto(
    customerId: 'customer-demo-1',
    customerName: 'Demo Customer',
    walletId: 'wallet-demo-1',
    amountMinorUnits: 50000,
    currencyCode: 'BWP',
  );

  test('posts the backend cash-in contract and parses its response resource',
      () async {
    when(() => dio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenAnswer((invocation) async => Response(
          data: responseBody,
          statusCode: 201,
          requestOptions: RequestOptions(path: '/cash-in'),
        ));

    final result = await repository.submitCashIn(request);

    expect(result.referenceNumber, 'CI-DEMO-001');
    expect(result.amountMinorUnits, 50000);
    expect(result.feeMinorUnits, 0);
    verify(() => dio.post<Map<String, dynamic>>(
          '/v1/agents/agent-demo-1/customers/customer-demo-1/cash-in',
          data: {
            'customer_wallet_id': 'wallet-demo-1',
            'amount': 50000,
            'currency_code': 'BWP',
          },
          options: any(named: 'options'),
        )).called(1);
  });

  test('sends an idempotency key and no PIN field in the transaction payload',
      () async {
    when(() => dio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenAnswer((_) async => Response(
          data: responseBody,
          statusCode: 201,
          requestOptions: RequestOptions(path: '/cash-in'),
        ));

    await repository.submitCashIn(request);

    final call = verify(() => dio.post<Map<String, dynamic>>(
          any(),
          data: captureAny(named: 'data'),
          options: captureAny(named: 'options'),
        )).captured;
    final body = call[0] as Map<String, dynamic>;
    final options = call[1] as Options;
    expect(body.containsKey('pin_hash'), isFalse);
    expect(body.containsKey('pin'), isFalse);
    expect(options.headers?['Idempotency-Key'], isNotEmpty);
  });
}
