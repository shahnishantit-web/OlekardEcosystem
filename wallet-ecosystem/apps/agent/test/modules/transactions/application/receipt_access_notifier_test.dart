import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_agent/modules/transactions/application/receipt_access_notifier.dart';
import 'package:wallet_agent/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_receipt.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

import 'agent_transaction_test.mocks.dart';

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  final receipt = PaymentReceipt(
    intentId: 'intent-1',
    referenceNumber: 'REF-001',
    issuedAt: DateTime.fromMillisecondsSinceEpoch(1730000000000),
    totalAmountMinorUnits: 50000,
    feeMinorUnits: 100,
    currencyCode: 'BWP',
    providerReference: '12345678',
    lineItems: [],
  );

  ProviderContainer makeContainer() => ProviderContainer(
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
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => receipt);

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptReady>());
    expect((state as ReceiptReady).receipt.intentId, 'intent-1');
  });

  // ─── SC-07: cross-account 403 → ReceiptAccessDenied ──────────────────────────

  test('SC-07: 403 on fetchReceipt → ReceiptAccessDenied', () async {
    when(mockRepo.fetchReceipt(any))
        .thenThrow(const AccessDeniedException('Access denied: not your receipt'));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptAccessDenied>());
    expect((state as ReceiptAccessDenied).reason, contains('Access denied'));
  });

  test('generic error on fetchReceipt → ReceiptAccessError', () async {
    when(mockRepo.fetchReceipt(any)).thenThrow(Exception('Network error'));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(receiptAccessProvider('intent-1').future);

    expect(state, isA<ReceiptAccessError>());
  });

  // ─── SC-06: requestDownload token ────────────────────────────────────────────

  test('SC-06: requestDownload() → ReceiptDownloadReady with downloadUrl', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => receipt);
    final expiry = DateTime(2026, 10, 27, 12, 0);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok-abc',
          downloadUrl: 'https://cdn.example.com/receipts/intent-1.pdf',
          expiresAt: expiry,
        ));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container.read(receiptAccessProvider('intent-1').notifier).requestDownload();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptDownloadReady>());
    final ready = state as ReceiptDownloadReady;
    expect(ready.downloadUrl, 'https://cdn.example.com/receipts/intent-1.pdf');
    expect(ready.expiresAt, expiry);
  });

  test('SC-06: downloadUrl never written to PaymentCacheStore', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => receipt);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok-abc',
          downloadUrl: 'https://cdn.example.com/receipts/intent-1.pdf',
          expiresAt: DateTime(2099),
        ));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container.read(receiptAccessProvider('intent-1').notifier).requestDownload();

    verifyNever(mockCache.writeDetail(any, any));
    verifyNever(mockCache.writeHistoryPage(any, any));
  });

  test('onDownloadLaunched() discards downloadUrl and resets to ReceiptReady', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => receipt);
    when(mockRepo.requestDownloadToken('intent-1')).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok',
          downloadUrl: 'https://cdn.example.com/receipt.pdf',
          expiresAt: DateTime(2099),
        ));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container.read(receiptAccessProvider('intent-1').notifier).requestDownload();

    expect(container.read(receiptAccessProvider('intent-1')).value,
        isA<ReceiptDownloadReady>());

    container.read(receiptAccessProvider('intent-1').notifier).onDownloadLaunched();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptReady>());
    expect(state, isNot(isA<ReceiptDownloadReady>()));
  });

  test('requestDownload failure → ReceiptDownloadError (receipt preserved)', () async {
    when(mockRepo.fetchReceipt('intent-1')).thenAnswer((_) async => receipt);
    when(mockRepo.requestDownloadToken(any))
        .thenThrow(Exception('Token service unavailable'));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(receiptAccessProvider('intent-1').future);
    await container.read(receiptAccessProvider('intent-1').notifier).requestDownload();

    final state = container.read(receiptAccessProvider('intent-1')).value;
    expect(state, isA<ReceiptDownloadError>());
    expect((state as ReceiptDownloadError).receipt.intentId, 'intent-1');
  });
}
