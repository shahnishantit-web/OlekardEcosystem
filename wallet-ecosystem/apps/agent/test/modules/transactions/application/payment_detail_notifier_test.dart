import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_agent/modules/transactions/application/payment_detail_notifier.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';
import 'package:wallet_agent/modules/transactions/domain/entities/domain_deep_link.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

import 'agent_transaction_test.mocks.dart';

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  PaymentHistoryItem makeItem({
    String id = 'intent-1',
    bool refundEligible = false,
    RefundStatus? refundStatus,
    DomainDeepLink? deepLink,
  }) =>
      PaymentHistoryItem(
        id: id,
        referenceNumber: 'REF-001',
        purposeType: PaymentPurposeType.walletTransfer,
        status: PaymentStatus.completed,
        amountMinorUnits: 50000,
        feeMinorUnits: 100,
        currencyCode: 'BWP',
        createdAt: DateTime(2026, 10, 1),
        captureStatus: CaptureStatus.captured,
        accountingStatus: AccountingStatus.posted,
        serviceStatus: ServiceStatus.confirmed,
        refundEligible: refundEligible,
        refundStatus: refundStatus,
        receiptAvailable: true,
        direction: PaymentDirection.debit,
        domainDeepLink: deepLink,
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
  });

  // ─── T085: US2 tests ──────────────────────────────────────────────────────────

  test('build() fetches item and transitions to PaymentDetailLoaded with 4 status rows', () async {
    final item = makeItem();
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentDetailProvider('intent-1').future);

    expect(state, isA<PaymentDetailLoaded>());
    final loaded = state as PaymentDetailLoaded;
    expect(loaded.item.id, 'intent-1');
    expect(loaded.statusRows.captureStatus, isNotNull);
    expect(loaded.statusRows.accountingStatus, isNotNull);
    expect(loaded.statusRows.serviceStatus, isNotNull);
    expect(loaded.statusRows.refundStatus, isNull);
  });

  test('build() with AvailableLink → deepLink populated in loaded state', () async {
    const link = AvailableLink(url: 'https://example.com/domain', label: 'Domain');
    final item = makeItem(deepLink: link);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentDetailProvider('intent-1').future);

    expect(state, isA<PaymentDetailLoaded>());
    final loaded = state as PaymentDetailLoaded;
    expect(loaded.deepLink, isA<AvailableLink>());
  });

  test('build() on exception → PaymentDetailError', () async {
    when(mockRepo.fetchDetail(any)).thenThrow(Exception('403 Forbidden'));

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentDetailProvider('intent-1').future);

    expect(state, isA<PaymentDetailError>());
  });

  test('unregistered deep link → UnavailableLink from item', () async {
    const link = UnavailableLink(reason: 'module_unavailable');
    final item = makeItem(deepLink: link);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    final state = await container.read(paymentDetailProvider('intent-1').future);

    expect(state, isA<PaymentDetailLoaded>());
    expect((state as PaymentDetailLoaded).deepLink, isA<UnavailableLink>());
  });

  // ─── T088: US4 refund tests ───────────────────────────────────────────────────

  test('initiateRefund() on refundEligible=true → PaymentDetailRefundConfirm', () async {
    final item = makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    await container.read(paymentDetailProvider('intent-1').notifier).initiateRefund();

    final state = container.read(paymentDetailProvider('intent-1')).value;
    expect(state, isA<PaymentDetailRefundConfirm>());
  });

  test('initiateRefund() on refundEligible=false → state unchanged (stays Loaded)', () async {
    final item = makeItem(refundEligible: false);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    await container.read(paymentDetailProvider('intent-1').notifier).initiateRefund();

    final state = container.read(paymentDetailProvider('intent-1')).value;
    expect(state, isA<PaymentDetailLoaded>());
  });

  test('submitRefund() → Submitting → Submitted', () async {
    final item = makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);
    when(mockRepo.submitRefund(any)).thenAnswer((_) async {});

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    final notifier = container.read(paymentDetailProvider('intent-1').notifier);
    await notifier.initiateRefund();
    await notifier.submitRefund(reason: 'wrong_item');

    final state = container.read(paymentDetailProvider('intent-1')).value;
    expect(state, isA<PaymentDetailRefundSubmitted>());
  });

  test('duplicate-submission guard: submitRefund when not in RefundConfirm is no-op', () async {
    final item = makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    // submitRefund without initiateRefund first — should be no-op
    await container
        .read(paymentDetailProvider('intent-1').notifier)
        .submitRefund(reason: 'test');

    verifyNever(mockRepo.submitRefund(any));
    expect(
        container.read(paymentDetailProvider('intent-1')).value,
        isA<PaymentDetailLoaded>());
  });

  test('E62 API error on submitRefund → PaymentDetailError', () async {
    final item = makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);
    when(mockRepo.submitRefund(any)).thenThrow(Exception('E62 server error'));

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    final notifier = container.read(paymentDetailProvider('intent-1').notifier);
    await notifier.initiateRefund();
    await notifier.submitRefund(reason: 'wrong_item');

    expect(
        container.read(paymentDetailProvider('intent-1')).value,
        isA<PaymentDetailError>());
  });

  test('cancel() from RefundConfirm → back to PaymentDetailLoaded', () async {
    final item = makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    final notifier = container.read(paymentDetailProvider('intent-1').notifier);
    await notifier.initiateRefund();
    expect(
        container.read(paymentDetailProvider('intent-1')).value,
        isA<PaymentDetailRefundConfirm>());

    await notifier.cancel();
    expect(
        container.read(paymentDetailProvider('intent-1')).value,
        isA<PaymentDetailLoaded>());
  });

  test('openSupportDraft() → PaymentDetailSupportDraft', () async {
    final item = makeItem();
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = makeContainer();
    addTearDown(container.dispose);
    await container.read(paymentDetailProvider('intent-1').future);

    await container
        .read(paymentDetailProvider('intent-1').notifier)
        .openSupportDraft();

    expect(
        container.read(paymentDetailProvider('intent-1')).value,
        isA<PaymentDetailSupportDraft>());
  });
}
