import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/di/providers.dart';
import '../../wallet/domain/entities/wallet.dart';

enum MoneyFlowKind { transfer, funding, withdrawal, service }

class PreparedMoneyPayment {
  final MoneyFlowKind kind;
  final String id;
  final String reference;
  final String recipient;
  final String currency;
  final int amount;
  final int fee;
  final Map<String, dynamic> request;
  const PreparedMoneyPayment(
      {required this.kind,
      required this.id,
      required this.reference,
      required this.recipient,
      required this.currency,
      required this.amount,
      required this.fee,
      required this.request});
}

/// Thin orchestration of the existing domain APIs; fees and outcomes are server-owned.
class CustomerMoneyService {
  final Dio dio;
  CustomerMoneyService(this.dio);

  Options _options(String key) => Options(headers: {
        'Idempotency-Key': key,
        'X-Idempotency-Key': key,
      });
  Map<String, dynamic> _data(Response response) =>
      (response.data['data'] ?? response.data) as Map<String, dynamic>;

  Future<PreparedMoneyPayment> prepare(
      {required MoneyFlowKind kind,
      required Wallet wallet,
      required int amount,
      required String target,
      required String method,
      required String idempotencyKey,
      String bankCode = '',
      String? serviceId}) async {
    if (wallet.status != WalletStatus.active) {
      throw StateError('Your wallet must be active before making a payment.');
    }
    final common = <String, dynamic>{
      'wallet_id': wallet.id,
      'currency_code': wallet.currencyCode,
      'gross_amount': amount
    };
    if (kind == MoneyFlowKind.service) {
      final validated = _data(await dio.post(
          '/api/v1/services/$serviceId/validate-reference',
          data: {'customer_reference': target}));
      final quote = _data(await dio.post(
          '/api/v1/services/$serviceId/calculate-fee',
          data: {'amount': amount}));
      if (quote['currency_code'] != wallet.currencyCode) {
        throw StateError(
            'This service requires a wallet in ${quote['currency_code']}.');
      }
      return PreparedMoneyPayment(
          kind: kind,
          id: serviceId!,
          reference: '',
          recipient: validated['account_name'] as String? ?? target,
          currency: quote['currency_code'] as String,
          amount: amount,
          fee: quote['fee_amount'] as int,
          request: {
            'wallet_id': wallet.id,
            'customer_reference': target,
            'account_name': validated['account_name'],
            'amount': amount,
            'currency_code': wallet.currencyCode,
            'quoted_fee_amount': quote['fee_amount'],
          });
    }
    late Map<String, dynamic> transaction;
    var name = target;
    if (kind == MoneyFlowKind.transfer) {
      final lookup =
          _data(await dio.post('/api/v1/transfers/recipient-lookup', data: {
        if (target.startsWith('+'))
          'phone_number': target
        else
          'wallet_number': target,
        'currency_code': wallet.currencyCode,
      }));
      final displayName = lookup['display_name'] as String;
      name = displayName.isNotEmpty
          ? displayName
          : lookup['masked_mobile'] as String? ?? target;
      transaction = _data(await dio.post('/api/v1/transfers',
          data: {...common, 'lookup_token': lookup['lookup_token']},
          options: _options(idempotencyKey)));
    } else if (kind == MoneyFlowKind.funding) {
      transaction = _data(await dio.post('/api/v1/funding/transactions',
          data: {...common, 'funding_method': method},
          options: _options(idempotencyKey)));
      name = method == 'bank_transfer' ? 'Bank transfer' : 'Mobile money';
    } else {
      transaction = _data(await dio.post('/api/v1/withdrawals',
          data: {
            ...common,
            'channel': method,
            'payout_destination': {
              if (method == 'bank_transfer') 'bank_code': bankCode,
              if (method == 'bank_transfer')
                'account_number': target
              else
                'phone_number': target
            },
          },
          options: _options(idempotencyKey)));
    }
    return PreparedMoneyPayment(
        kind: kind,
        id: transaction['id'] as String,
        reference: (transaction['transfer_reference'] ??
            transaction['transaction_reference']) as String,
        recipient: name,
        currency: wallet.currencyCode,
        amount: transaction['gross_amount'] as int,
        fee: transaction['fee_amount'] as int,
        request: transaction);
  }

  Future<String> verifyStepUp(String pin,
      {String scope = 'service_payment'}) async {
    final challenge = _data(await dio.post('/v1/auth/step-up/initiate', data: {
      'scope': scope,
      'operation_context': {'risk_required': true},
    }));
    final result = _data(await dio.post('/v1/auth/step-up/verify', data: {
      'step_up_token': challenge['step_up_token'],
      'method': 'pin',
      'credential': pin,
    }));
    return result['step_up_access_token'] as String;
  }

  Future<Map<String, dynamic>> confirm(
      PreparedMoneyPayment payment, String pin, String key) async {
    if (payment.kind == MoneyFlowKind.service) {
      final token = await verifyStepUp(pin);
      return _data(await dio.post('/api/v1/services/${payment.id}/payments',
          data: {...payment.request, 'step_up_token': token},
          options: _options(key)));
    }
    final path = switch (payment.kind) {
      MoneyFlowKind.transfer => '/api/v1/transfers',
      MoneyFlowKind.funding => '/api/v1/funding/transactions',
      _ => '/api/v1/withdrawals',
    };
    final options = _options(key);
    if (payment.kind == MoneyFlowKind.transfer ||
        payment.kind == MoneyFlowKind.withdrawal) {
      options.headers!['X-StepUp-Token'] = await verifyStepUp(pin,
          scope: payment.kind == MoneyFlowKind.transfer
              ? 'transfer.confirm'
              : 'withdrawal.confirm');
    }
    return _data(await dio.post('$path/${payment.id}/confirm',
        data: {
          'transaction_pin': pin,
          if (payment.kind == MoneyFlowKind.funding)
            'accepted_fee_amount': payment.fee,
          if (payment.kind == MoneyFlowKind.withdrawal)
            'quoted_fee_amount': payment.fee,
        },
        options: options));
  }

  Future<Map<String, dynamic>> status(MoneyFlowKind kind, String id) async {
    final path = switch (kind) {
      MoneyFlowKind.transfer => '/api/v1/transfers/$id',
      MoneyFlowKind.funding => '/api/v1/funding/transactions/$id',
      MoneyFlowKind.withdrawal => '/api/v1/withdrawals/$id',
      MoneyFlowKind.service => '/api/v1/services/payments/$id',
    };
    return _data(await dio.get(path));
  }

  Future<Map<String, dynamic>> completeMockBank(String id, String key,
          {bool withdrawal = false}) async =>
      _data(await dio.post(
          '/api/v1/dev/mock-bank/${withdrawal ? 'withdrawals' : 'funding'}/$id/complete',
          options: _options(key)));
}

final customerMoneyServiceProvider =
    Provider((ref) => CustomerMoneyService(ref.watch(apiClientProvider).dio));

/// Integer conversion keeps decimal money out of binary floating-point calculations.
int parseMinorAmount(String input, {int precision = 2}) {
  final text = input.trim();
  if (precision < 0 ||
      precision > 6 ||
      !RegExp(r'^\d+(\.\d+)?$').hasMatch(text)) {
    throw const FormatException('Enter a positive amount.');
  }
  final parts = text.split('.');
  final fraction = parts.length == 2 ? parts[1] : '';
  if (fraction.length > precision) {
    throw FormatException('Use at most $precision decimal places.');
  }
  final minor = int.tryParse(parts[0] + fraction.padRight(precision, '0'));
  if (minor == null || minor <= 0 || minor > 9007199254740991) {
    throw const FormatException('Enter a valid positive amount.');
  }
  return minor;
}

String paymentErrorMessage(Object error) {
  if (error is DioException) {
    final body = error.response?.data;
    final detail = body is Map ? body['error'] : null;
    final message = detail is Map
        ? detail['message']
        : (body is Map ? body['message'] : null);
    if (message is String) return message;
    if (detail is String) return detail;
    return 'Unable to complete the request. Check your connection and try again.';
  }
  if (error is FormatException) return error.message;
  if (error is StateError) return error.message;
  return 'Unable to complete the request. Please try again.';
}
