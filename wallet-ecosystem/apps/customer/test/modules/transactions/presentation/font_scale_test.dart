// T080: Font scale 2× test for P23 screens — no overflow at textScaler: 2.0
// ignore_for_file: type=lint
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/core/api/paginated_result.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/transaction.dart';
import 'package:wallet_customer/modules/transactions/presentation/screens/transaction_list_screen.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_providers.dart';

import '../application/payment_history_notifier_test.mocks.dart';

PaymentHistoryItem _makeItem(String id) => PaymentHistoryItem(
      id: id,
      referenceNumber: 'REF-$id',
      purposeType: PaymentPurposeType.walletTransfer,
      status: PaymentStatus.completed,
      amountMinorUnits: 500000,
      feeMinorUnits: 1000,
      currencyCode: 'BWP',
      createdAt: DateTime(2026, 10, 1),
      captureStatus: CaptureStatus.captured,
      accountingStatus: AccountingStatus.posted,
      serviceStatus: ServiceStatus.confirmed,
      refundEligible: false,
      receiptAvailable: false,
      direction: PaymentDirection.debit,
      counterpartyName: 'A Very Long Counterparty Name For Testing Overflow',
    );

Widget _wrapAt2x(ProviderContainer container) => UncontrolledProviderScope(
      container: container,
      child: MediaQuery(
        data: const MediaQueryData(textScaler: TextScaler.linear(2.0)),
        child: const MaterialApp(home: TransactionListScreen()),
      ),
    );

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  setUp(() {
    mockRepo = MockTransactionRepository();
    mockCache = MockPaymentCacheStore();
    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => null);
    when(mockCache.writeHistoryPage(any, any)).thenAnswer((_) async {});
  });

  ProviderContainer _container() => ProviderContainer(overrides: [
        transactionRepositoryProvider.overrideWithValue(mockRepo),
        paymentCacheStoreProvider.overrideWithValue(mockCache),
      ]);

  // ─── T080: No overflow at 2× font scale ──────────────────────────────────────

  testWidgets('HistoryFilterBar: no text overflow at 2× scale', (tester) async {
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async =>
        const PaginatedResult(data: [], currentPage: 1, total: 0, lastPage: 1));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrapAt2x(container));
    await tester.pumpAndSettle();

    // Empty state shows "No transactions found" — must not overflow
    expect(tester.takeException(), isNull);
  });

  testWidgets('Transaction list tiles: no overflow at 2× scale', (tester) async {
    final items = [_makeItem('1'), _makeItem('2')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async =>
        PaginatedResult(data: items, currentPage: 1, total: 2, lastPage: 1));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrapAt2x(container));
    await tester.pumpAndSettle();

    // Tiles must render without layout overflow
    expect(tester.takeException(), isNull);
  });
}
