import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/annotations.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/core/api/paginated_result.dart';
import 'package:wallet_customer/modules/transactions/application/payment_history_notifier.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/transaction.dart';
import 'package:wallet_customer/modules/transactions/domain/repositories/transaction_repository.dart';
import 'package:wallet_customer/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_repository_provider.dart';

import 'payment_history_notifier_test.mocks.dart';

@GenerateMocks([TransactionRepository, PaymentCacheStore])
void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

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
        receiptAvailable: true,
        direction: PaymentDirection.debit,
      );

  PaginatedResult<PaymentHistoryItem> _page(List<PaymentHistoryItem> items) =>
      PaginatedResult(
        data: items,
        currentPage: 1,
        total: items.length,
        lastPage: 1,
      );

  ProviderContainer _container() {
    return ProviderContainer(
      overrides: [
        transactionRepositoryProvider.overrideWithValue(mockRepo),
        paymentCacheStoreProvider.overrideWithValue(mockCache),
      ],
    );
  }

  setUp(() {
    mockRepo = MockTransactionRepository();
    mockCache = MockPaymentCacheStore();
    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => null);
  });

  // ─── SC-01: list loads ────────────────────────────────────────────────────────

  test('SC-01: build() fetches and transitions to PaymentHistoryLoaded', () async {
    final items = [_makeItem('1'), _makeItem('2'), _makeItem('3')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => _page(items));

    final container = _container();
    addTearDown(container.dispose);

    await container
        .read(paymentHistoryProvider('user1').future)
        .then((_) => null)
        .catchError((_) => null);

    // Allow async build to complete
    final state = await container.read(paymentHistoryProvider('user1').future);

    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.length, 3);
    expect(loaded.isStale, isFalse);
  });

  test('SC-01: empty page → PaymentHistoryEmpty', () async {
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => _page([]));

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('user1').future);

    expect(state, isA<PaymentHistoryEmpty>());
  });

  // ─── SC-02: filter applied ────────────────────────────────────────────────────

  test('SC-02: applyFilter calls fetchHistory with new filter and reloads', () async {
    final items = [_makeItem('1')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => _page(items));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(paymentHistoryProvider('user1').future);

    final filteredItems = [_makeItem('2')];
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => _page(filteredItems));

    final notifier = container.read(paymentHistoryProvider('user1').notifier);
    await notifier.applyFilter(const PaymentHistoryFilter(
      status: PaymentStatus.completed,
    ));

    final state = container.read(paymentHistoryProvider('user1')).value;
    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.length, 1);
    expect(loaded.items.first.id, '2');

    // Verify filter was passed to repo (page reset to 1)
    final captured = verify(mockRepo.fetchHistory(captureAny)).captured;
    final lastFilter = captured.last as PaymentHistoryFilter;
    expect(lastFilter.status, PaymentStatus.completed);
    expect(lastFilter.page, 1);
  });

  // ─── SC-03: offline stale served with banner ──────────────────────────────────

  test('SC-03: network failure + cached data → PaymentHistoryError(isOffline, staleItems)', () async {
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
                'receipt_available': true,
                'direction': 'debit',
              })
          .toList(),
      'meta': {'current_page': 1, 'total': 1, 'last_page': 1},
    });

    final cachedAt = DateTime(2026, 10, 1);
    when(mockCache.readHistoryPage('user1')).thenAnswer((_) async =>
        PaymentCacheEntry(
          rawJson: cachedJson,
          cachedAt: cachedAt,
          staleAfterSeconds: 0,
        ));

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('user1').future);

    expect(state, isA<PaymentHistoryError>());
    final error = state as PaymentHistoryError;
    expect(error.isOffline, isTrue);
    expect(error.staleItems, isNotNull);
    expect(error.staleItems!.length, 1);
    expect(error.staleItems!.first.id, 'stale1');
    expect(error.staleAt, cachedAt);
  });

  test('SC-03: network failure + no cache → PaymentHistoryError(isOffline, no staleItems)', () async {
    when(mockRepo.fetchHistory(any)).thenThrow(DioException(
      requestOptions: RequestOptions(),
      type: DioExceptionType.connectionError,
    ));
    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => null);

    final container = _container();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('user1').future);

    expect(state, isA<PaymentHistoryError>());
    final error = state as PaymentHistoryError;
    expect(error.isOffline, isTrue);
    expect(error.staleItems, isNull);
  });

  // ─── Pagination ───────────────────────────────────────────────────────────────

  test('loadNextPage appends items when hasNextPage is true', () async {
    final page1Items = [_makeItem('1'), _makeItem('2')];
    final page2Items = [_makeItem('3')];

    when(mockRepo.fetchHistory(any)).thenAnswer((inv) async {
      final filter = inv.positionalArguments[0] as PaymentHistoryFilter;
      if (filter.page == 1) {
        return PaginatedResult(
          data: page1Items,
          currentPage: 1,
          total: 3,
          lastPage: 2,
        );
      }
      return PaginatedResult(
        data: page2Items,
        currentPage: 2,
        total: 3,
        lastPage: 2,
      );
    });

    final container = _container();
    addTearDown(container.dispose);
    await container.read(paymentHistoryProvider('user1').future);

    await container
        .read(paymentHistoryProvider('user1').notifier)
        .loadNextPage();

    final state = container.read(paymentHistoryProvider('user1')).value;
    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.length, 3);
    expect(loaded.items.map((i) => i.id).toList(), ['1', '2', '3']);
  });

  test('loadNextPage is no-op when not on last page', () async {
    final items = [_makeItem('1')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => _page(items));

    final container = _container();
    addTearDown(container.dispose);
    await container.read(paymentHistoryProvider('user1').future);

    await container
        .read(paymentHistoryProvider('user1').notifier)
        .loadNextPage();

    // Only one call (initial build)
    verify(mockRepo.fetchHistory(any)).called(1);
  });
}
