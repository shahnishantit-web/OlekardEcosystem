import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_agent/core/api/api_response.dart';
import 'package:wallet_agent/modules/transactions/application/payment_history_notifier.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart';
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

import 'agent_transaction_test.mocks.dart';

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  PaymentHistoryItem makeItem(String id) => PaymentHistoryItem(
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

  PaginatedResult<PaymentHistoryItem> page(List<PaymentHistoryItem> items) =>
      PaginatedResult(
        data: items,
        currentPage: 1,
        total: items.length,
        lastPage: 1,
      );

  ProviderContainer makeContainer() {
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
    final items = [makeItem('1'), makeItem('2'), makeItem('3')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => page(items));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('agent1').future);

    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.length, 3);
    expect(loaded.isStale, isFalse);
  });

  test('SC-01: empty page → PaymentHistoryEmpty', () async {
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => page([]));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('agent1').future);

    expect(state, isA<PaymentHistoryEmpty>());
  });

  test('SC-01: agent-scoped JWT produces agent-scoped items', () async {
    final agentItems = [makeItem('a1'), makeItem('a2')];
    when(mockRepo.fetchHistory(any)).thenAnswer((_) async => page(agentItems));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('agent1').future);

    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.every((i) => i.id.startsWith('a')), isTrue);
  });

  // ─── SC-02: filter applied ────────────────────────────────────────────────────

  test('SC-02: applyFilter reloads with new filter and resets page to 1', () async {
    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => page([makeItem('1')]));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentHistoryProvider('agent1').future);

    when(mockRepo.fetchHistory(any))
        .thenAnswer((_) async => page([makeItem('2')]));

    await container
        .read(paymentHistoryProvider('agent1').notifier)
        .applyFilter(const PaymentHistoryFilter(status: PaymentStatus.completed));

    final state = container.read(paymentHistoryProvider('agent1')).value;
    expect(state, isA<PaymentHistoryLoaded>());
    final loaded = state as PaymentHistoryLoaded;
    expect(loaded.items.first.id, '2');

    final captured = verify(mockRepo.fetchHistory(captureAny)).captured;
    final lastFilter = captured.last as PaymentHistoryFilter;
    expect(lastFilter.status, PaymentStatus.completed);
    expect(lastFilter.page, 1);
  });

  // ─── SC-03: offline stale ─────────────────────────────────────────────────────

  test('SC-03: network failure + cached data → PaymentHistoryError(isOffline, staleItems)', () async {
    when(mockRepo.fetchHistory(any)).thenThrow(DioException(
      requestOptions: RequestOptions(),
      type: DioExceptionType.connectionError,
    ));

    final cachedItems = [makeItem('stale1')];
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
      'current_page': 1,
      'total': 1,
      'last_page': 1,
    });

    final cachedAt = DateTime(2026, 10, 1);
    when(mockCache.readHistoryPage('agent1')).thenAnswer((_) async =>
        PaymentCacheEntry(
          rawJson: cachedJson,
          cachedAt: cachedAt,
          staleAfterSeconds: 0,
        ));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('agent1').future);

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

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentHistoryProvider('agent1').future);

    expect(state, isA<PaymentHistoryError>());
    final error = state as PaymentHistoryError;
    expect(error.isOffline, isTrue);
    expect(error.staleItems, isNull);
  });
}
