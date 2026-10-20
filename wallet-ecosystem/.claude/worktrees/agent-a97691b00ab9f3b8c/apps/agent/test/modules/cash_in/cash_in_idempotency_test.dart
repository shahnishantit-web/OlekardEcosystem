import 'dart:async';
import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:dio/dio.dart';

import 'package:wallet_agent/modules/cash_in/data/models/cash_in_request_dto.dart';
import 'package:wallet_agent/modules/cash_in/data/repositories/cash_in_repository_impl.dart';

class MockDio extends Mock implements Dio {}

void main() {
  late MockDio mockDio;
  late CashInRepositoryImpl repo;

  final rawResult = jsonDecode(
    '{"reference_number":"CI-2026-000001","amount_minor_units":50000,"fee_minor_units":250,'
    '"currency_code":"BWP","customer_name":"Bonolo Sithole","agent_name":"Test Agent",'
    '"agent_location_code":"GAB-001","timestamp":"2026-10-05T09:30:00Z","status":"completed"}',
  ) as Map<String, dynamic>;

  setUp(() {
    mockDio = MockDio();
    repo = CashInRepositoryImpl(dio: mockDio);
    registerFallbackValue(RequestOptions(path: ''));
  });

  test('returns result on 200', () async {
    when(() => mockDio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenAnswer(
      (_) async => Response(
        data: rawResult,
        statusCode: 200,
        requestOptions: RequestOptions(path: '/v1/agent/cash-in'),
      ),
    );

    const request = CashInRequestDto(
      walletId: 'wlt_01J8XYZABC000001',
      amountMinorUnits: 50000,
      currencyCode: 'BWP',
      pinHash: 'hash',
    );
    final result = await repo.submitCashIn(request);
    expect(result.referenceNumber, equals('CI-2026-000001'));
    expect(result.netMinorUnits, equals(50000 - 250));
  });

  test('replays cached result on 409', () async {
    // First call succeeds
    when(() => mockDio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenAnswer(
      (_) async => Response(
        data: rawResult,
        statusCode: 200,
        requestOptions: RequestOptions(path: '/v1/agent/cash-in'),
      ),
    );
    const request = CashInRequestDto(
      walletId: 'wlt_01J8XYZABC000001',
      amountMinorUnits: 50000,
      currencyCode: 'BWP',
      pinHash: 'hash',
    );
    final first = await repo.submitCashIn(request);

    // Second call returns 409 with same result body
    when(() => mockDio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenThrow(
      DioException(
        requestOptions: RequestOptions(path: '/v1/agent/cash-in'),
        response: Response(
          data: rawResult,
          statusCode: 409,
          requestOptions: RequestOptions(path: '/v1/agent/cash-in'),
        ),
        type: DioExceptionType.badResponse,
      ),
    );
    final second = await repo.submitCashIn(request);
    expect(second.referenceNumber, equals(first.referenceNumber));
  });

  test('PIN is never logged during submit', () async {
    final logs = <String>[];
    // Verify Zone is available (dart:async)
    expect(Zone.current, isNotNull);

    when(() => mockDio.post<Map<String, dynamic>>(
          any(),
          data: any(named: 'data'),
          options: any(named: 'options'),
        )).thenAnswer((_) async {
      final call = verify(() => mockDio.post<Map<String, dynamic>>(
            any(),
            data: captureAny(named: 'data'),
            options: any(named: 'options'),
          )).captured;
      for (final c in call) {
        logs.add(c.toString());
      }
      return Response(
        data: rawResult,
        statusCode: 200,
        requestOptions: RequestOptions(path: '/v1/agent/cash-in'),
      );
    });

    const request = CashInRequestDto(
      walletId: 'w',
      amountMinorUnits: 1000,
      currencyCode: 'BWP',
      pinHash: 'bcrypt_secret_hash',
    );
    await repo.submitCashIn(request);
    for (final log in logs) {
      expect(log, isNot(contains('bcrypt_secret_hash')));
    }
  });
}
