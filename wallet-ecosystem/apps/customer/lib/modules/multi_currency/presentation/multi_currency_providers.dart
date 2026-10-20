import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../domain/entities/currency_wallet.dart';
import '../domain/entities/fx_quote.dart';
import '../../../../core/di/providers.dart';

// ── Currency wallet list ────────────────────────────────────────────────────

final currencyWalletsProvider = FutureProvider<List<CurrencyWallet>>((ref) async {
  final dio  = ref.watch(apiClientProvider).dio;
  final res  = await dio.get('/v1/multi-currency/wallets');
  final data = (res.data['data'] as List<dynamic>?) ?? [];
  return data
      .cast<Map<String, dynamic>>()
      .map(CurrencyWallet.fromJson)
      .toList();
});

// ── Active currencies (for dropdowns) ──────────────────────────────────────

final activeCurrenciesProvider = FutureProvider<List<Map<String, dynamic>>>((ref) async {
  final dio = ref.watch(apiClientProvider).dio;
  final res = await dio.get('/v1/multi-currency/currencies');
  return ((res.data['data'] as List<dynamic>?) ?? [])
      .cast<Map<String, dynamic>>();
});

// ── Create currency wallet ──────────────────────────────────────────────────

final createCurrencyWalletProvider =
    FutureProvider.family<CurrencyWallet, String>((ref, currencyCode) async {
  final dio = ref.watch(apiClientProvider).dio;
  final res = await dio.post(
    '/v1/multi-currency/wallets',
    data: {'currency_code': currencyCode},
  );
  return CurrencyWallet.fromJson(res.data['data'] as Map<String, dynamic>);
});

// ── FX quote request ────────────────────────────────────────────────────────

class FxQuoteParams {
  const FxQuoteParams({
    required this.fromCurrency,
    required this.toCurrency,
    required this.fromAmount,
  });

  final String fromCurrency;
  final String toCurrency;
  final String fromAmount;
}

final fxQuoteRequestProvider =
    FutureProvider.family<FxQuote, FxQuoteParams>((ref, params) async {
  final dio = ref.watch(apiClientProvider).dio;
  final res = await dio.post(
    '/v1/multi-currency/fx-quotes',
    data: {
      'from_currency_code': params.fromCurrency,
      'to_currency_code':   params.toCurrency,
      'from_amount':        params.fromAmount,
    },
  );
  return FxQuote.fromJson(res.data['data'] as Map<String, dynamic>);
});

// ── FX conversion confirm ───────────────────────────────────────────────────

final fxConversionConfirmProvider =
    FutureProvider.family<Map<String, dynamic>, String>((ref, quoteId) async {
  final dio    = ref.watch(apiClientProvider).dio;
  final idemKey = DateTime.now().millisecondsSinceEpoch.toString();
  final res = await dio.post(
    '/v1/multi-currency/fx-quotes/$quoteId/confirm',
    data: {'idempotency_key': idemKey},
  );
  return res.data['data'] as Map<String, dynamic>;
});

// ── Same-currency transfer ──────────────────────────────────────────────────

class SameCurrencyTransferParams {
  const SameCurrencyTransferParams({
    required this.fromWalletId,
    required this.toWalletId,
    required this.amount,
  });

  final String fromWalletId;
  final String toWalletId;
  final String amount;
}

final sameCurrencyTransferProvider =
    FutureProvider.family<Map<String, dynamic>, SameCurrencyTransferParams>(
        (ref, params) async {
  final dio     = ref.watch(apiClientProvider).dio;
  final idemKey = DateTime.now().millisecondsSinceEpoch.toString();
  final res = await dio.post(
    '/v1/multi-currency/transfers',
    data: {
      'from_wallet_id':   params.fromWalletId,
      'to_wallet_id':     params.toWalletId,
      'amount':           params.amount,
      'idempotency_key':  idemKey,
    },
  );
  return res.data['data'] as Map<String, dynamic>;
});
