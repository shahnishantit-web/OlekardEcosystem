// T035: Widget tests for TransactionListScreen
// ignore_for_file: type=lint
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/core/api/paginated_result.dart';
import 'package:wallet_customer/modules/transactions/application/payment_history_notifier.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/transaction.dart';
import 'package:wallet_customer/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_customer/modules/transactions/presentation/screens/transaction_list_screen.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_providers.dart';
import 'package:wallet_customer/shared/widgets/transaction_list_tile.dart';

import '../application/payment_history_notifier_test.mocks.dart';

PaymentHistoryItem _makeItem(String id) => PaymentHistoryItem(
      id: id,
      referenceNumber: 'REF-$id',
      purposeType: PaymentPurposeType.walletTransfer,
      status: PaymentStatus.completed,
      amountMinorUnits: 10000,
      feeMinorUnits: 50,
      currencyCode: 'BWP',
      createdAt: DateTime(2026, 10, 1),
      captureStatus: CaptureStatus.captured,
      accountingStatus: AccountingStatus.posted,
      serviceStatus: ServiceStatus.confirmed,
      refundEligible: false,
      receiptAvailable: false,
      direction: PaymentDirection.debit,
    );

PaginatedResult<PaymentHistoryItem> _page(List<PaymentHistoryItem> items) =>
    PaginatedResult(
        data: items, currentPage: 1, total: items.length, lastPage: 1);

Widget _wrap(ProviderContainer container) => UncontrolledProviderScope(
      container: container,
      child: const MaterialApp(home: TransactionListScreen()),
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

  // ─── SC-01: 3 tiles render ────────────────────────────────────────────────────

  testWidgets('SC-01: 3 list tiles render for 3 history items', (tester) async {
    final items = [_makeItem('1'), _makeItem('2'), _makeItem('3')];
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => _page(items));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // 3 TransactionListTile widgets for 3 items
    expect(find.byType(TransactionListTile), findsNWidgets(3));
  });

  // ─── Stale banner visible in offline state ────────────────────────────────────

  testWidgets('stale banner visible when offline + cached data', (tester) async {
    when(mockRepo.fetchHistory(any)).thenThrow(DioException(
      requestOptions: RequestOptions(),
      type: DioExceptionType.connectionError,
    ));

    final cachedItems = [_makeItem('stale1')];
    final cachedJson = jsonEncode({
      'data': cachedItems
          .map((i) => {
                'id': i.id,
                'reference_number': i.referenceNumber,
                'purpose_type': 'wallet_transfer',
                'status': 'completed',
                'amount_minor_units': i.amountMinorUnits,
                'fee_minor_units': i.feeMinorUnits,
                'currency_code': i.currencyCode,
                'created_at': i.createdAt.toIso8601String(),
                'capture_status': 'captured',
                'accounting_status': 'posted',
                'service_status': 'confirmed',
                'refund_eligible': false,
                'receipt_available': false,
                'direction': 'debit',
              })
          .toList(),
      'current_page': 1,
      'total': 1,
      'last_page': 1,
    });

    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => PaymentCacheEntry(
          rawJson: cachedJson,
          cachedAt: DateTime(2026, 10, 1),
          staleAfterSeconds: 0,
        ));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // Stale banner contains "Last updated" or "cached"
    expect(
      find.textContaining(RegExp(r'(Last updated|Showing cached)', caseSensitive: false)),
      findsOneWidget,
    );
    // Stale item tile still visible
    expect(find.byType(TransactionListTile), findsOneWidget);
  });

  // ─── SC-02: filter chip calls applyFilter ────────────────────────────────────

  testWidgets('SC-02: tapping Completed chip calls applyFilter with status=completed',
      (tester) async {
    final initial = [_makeItem('1')];
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => _page(initial));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // The filter bar shows status chips; tap the "Completed" FilterChip
    await tester.tap(find.widgetWithText(FilterChip, 'Completed'));
    await tester.pumpAndSettle();

    final captured = verify(mockRepo.fetchHistory(captureAny)).captured;
    expect(captured.length, greaterThanOrEqualTo(2));
    final lastFilter = captured.last as PaymentHistoryFilter;
    expect(lastFilter.status, PaymentStatus.completed);
    expect(lastFilter.page, 1);
  });

  // ─── Touch target ≥ 44dp ─────────────────────────────────────────────────────

  testWidgets('each tile touch target height ≥ 44dp', (tester) async {
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => _page([_makeItem('1')]));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // Each tile is wrapped in SizedBox(height: 72) by _ItemList — well above 44dp
    final tile = find.byType(TransactionListTile).first;
    final size = tester.getSize(tile);
    expect(size.height, greaterThanOrEqualTo(44.0));
    expect(size.width, greaterThanOrEqualTo(44.0));
  });
}
