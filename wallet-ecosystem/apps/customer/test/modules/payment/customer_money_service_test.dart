import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/payment/application/customer_money_service.dart';

void main() {
  test('converts money exactly and rejects invalid input', () {
    expect(parseMinorAmount('0.29'), 29);
    expect(parseMinorAmount('123.45'), 12345);
    expect(parseMinorAmount('12', precision: 0), 12);
    for (final value in ['0', '-1', '1.001', '1e3', 'NaN', '']) {
      expect(() => parseMinorAmount(value), throwsFormatException);
    }
  });
  test('transfer confirmation uses scoped proof and stable request keys',
      () async {
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'));
    dio.interceptors.add(InterceptorsWrapper(onRequest: (request, handler) {
      calls.add(request);
      final data = request.path.endsWith('/initiate')
          ? {'step_up_token': 'challenge'}
          : request.path.endsWith('/verify')
              ? {'step_up_access_token': 'verified-proof'}
              : {'id': 'transfer', 'status': 'completed'};
      handler.resolve(Response(
          requestOptions: request, data: {'data': data}, statusCode: 200));
    }));
    final result = await CustomerMoneyService(dio).confirm(
        const PreparedMoneyPayment(
            kind: MoneyFlowKind.transfer,
            id: 'transfer',
            reference: 'ref',
            recipient: 'recipient',
            currency: 'BWP',
            amount: 100,
            fee: 0,
            request: {}),
        '123456',
        'stable-key');
    expect(result['status'], 'completed');
    expect(calls[0].data['scope'], 'transfer.confirm');
    expect(calls[1].data['credential'], '123456');
    expect(calls.last.headers['X-StepUp-Token'], 'verified-proof');
    expect(calls.last.headers['Idempotency-Key'], 'stable-key');
    expect(calls.last.headers['X-Idempotency-Key'], 'stable-key');
    expect(calls.last.data['transaction_pin'], '123456');
  });
  test('failed proof never submits a payment', () async {
    final paths = <String>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://localhost'));
    dio.interceptors.add(InterceptorsWrapper(onRequest: (request, handler) {
      paths.add(request.path);
      handler.reject(DioException(
          requestOptions: request,
          response: Response(requestOptions: request, statusCode: 401)));
    }));
    await expectLater(
        CustomerMoneyService(dio).confirm(
            const PreparedMoneyPayment(
                kind: MoneyFlowKind.withdrawal,
                id: 'withdrawal',
                reference: 'ref',
                recipient: 'bank',
                currency: 'BWP',
                amount: 100,
                fee: 0,
                request: {}),
            'wrong',
            'stable-key'),
        throwsA(isA<DioException>()));
    expect(paths, ['/v1/auth/step-up/initiate']);
  });
}
