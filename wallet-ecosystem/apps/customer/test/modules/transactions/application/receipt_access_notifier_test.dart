import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/modules/transactions/application/receipt_access_notifier.dart';
import 'package:wallet_customer/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/payment_receipt.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_repository_provider.dart';

import 'payment_history_notifier_test.mocks.dart';

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  final _receipt = PaymentReceipt(
    intentId: 'intent-1',
    referenceNumber: 'REF-001',
    issuedAt: DateTime.fromMillisecondsSinceEpoch(1730000000000),
    totalAmountMinorUnits: 50000,
    feeMinorUnits: 100,
    currencyCode: 'BWP',
    providerReference: '12345678',
    lineItems: [],
  );

  ProviderContainer _container() => ProviderContainer(
        overrides: [
          transactionRepositoryProvider.overrideWithValue(mockRepo),
          paymentCacheStoreProvider.overrideWithValue(mockCache),
        ],
      );

  setUp(() {
    mockRepo = MockTransactionRepository();
    mockCache = MockPaymentCacheStore();
    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => null);
  });

  // ─── build() ─────────────────────────────────────────────────────────────────

  test('build() fetches receipt → ReceiptReady', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => _receipt);

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptReady>());
    final ready = state as ReceiptReady;
    expect(ready.receipt.intentId, 'intent-1');
  });

  // ─── SC-07: cross-account 403 → ReceiptAccessDenied ──────────────────────────

  test('SC-07: 403 on fetchReceipt → ReceiptAccessDenied', () async {
    when(mockRepo.fetchReceipt(any))
        .thenThrow(const AccessDeniedException('Access denied: not your receipt'));

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptAccessDenied>());
    final denied = state as ReceiptAccessDenied;
    expect(denied.reason, contains('Access denied'));
  });

  test('generic error on fetchReceipt → ReceiptAccessError', () async {
    when(mockRepo.fetchReceipt(any)).thenThrow(Exception('Network error'));

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptAccessError>());
  });

  // ─── SC-06: requestDownload → ReceiptDownloadReady ───────────────────────────

  test('SC-06: requestDownload() → ReceiptDownloadReady with downloadUrl', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => _receipt);
    final expiry = DateTime(2026, 10, 27, 12, 0);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok-abc',
          downloadUrl: 'https://cdn.example.com/receipts/intent-1.pdf',
          expiresAt: expiry,
        ));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);

    await container
        .read(receiptAccessProvider('intent-1').notifier)
        .requestDownload();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptDownloadReady>());
    final ready = state as ReceiptDownloadReady;
    expect(ready.downloadUrl, 'https://cdn.example.com/receipts/intent-1.pdf');
    expect(ready.expiresAt, expiry);
  });

  // ─── SC-06: downloadUrl not persisted to cache ────────────────────────────────

  test('SC-06: downloadUrl never written to PaymentCacheStore', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => _receipt);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok-abc',
          downloadUrl: 'https://cdn.example.com/receipts/intent-1.pdf',
          expiresAt: DateTime(2099),
        ));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container
        .read(receiptAccessProvider('intent-1').notifier)
        .requestDownload();

    // PaymentCacheStore.writeDetail must never have been called with the URL
    verifyNever(mockCache.writeDetail(any, any));
    verifyNever(mockCache.writeHistoryPage(any, any));
  });

  // ─── onDownloadLaunched resets to ReceiptReady ───────────────────────────────

  test('onDownloadLaunched() discards downloadUrl and resets to ReceiptReady', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => _receipt);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok',
          downloadUrl: 'https://cdn.example.com/receipt.pdf',
          expiresAt: DateTime(2099),
        ));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container
        .read(receiptAccessProvider('intent-1').notifier)
        .requestDownload();

    // Verify in DownloadReady state
    expect(container.read(receiptAccessProvider('intent-1')).value,
        isA<ReceiptDownloadReady>());

    // Simulate widget calling onDownloadLaunched after url_launcher
    container.read(receiptAccessProvider('intent-1').notifier).onDownloadLaunched();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptReady>());

    // Critically: no ReceiptDownloadReady anymore → URL is gone from state
    expect(state, isNot(isA<ReceiptDownloadReady>()));
  });

  // ─── requestDownload error ────────────────────────────────────────────────────

  test('requestDownload failure → ReceiptDownloadError (receipt preserved)', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => _receipt);
    when(mockRepo.requestDownloadToken(any))
        .thenThrow(Exception('Token service unavailable'));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container
        .read(receiptAccessProvider('intent-1').notifier)
        .requestDownload();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptDownloadError>());
    final err = state as ReceiptDownloadError;
    expect(err.receipt.intentId, 'intent-1');
  });
}
