// T046 + T069: Widget tests for TransactionDetailScreen
// ignore_for_file: type=lint
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/modules/transactions/domain/entities/domain_deep_link.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/transaction.dart';
import 'package:wallet_customer/modules/transactions/presentation/screens/transaction_detail_screen.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_providers.dart';
import 'package:wallet_customer/modules/transactions/presentation/widgets/payment_status_row.dart';

import '../application/payment_history_notifier_test.mocks.dart';

PaymentHistoryItem _makeItem({
  String id = 'intent-1',
  bool refundEligible = false,
  RefundStatus? refundStatus,
  bool receiptAvailable = false,
  DomainDeepLink? deepLink,
  String? providerReference,
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
      receiptAvailable: receiptAvailable,
      direction: PaymentDirection.debit,
      domainDeepLink: deepLink,
      providerReference: providerReference,
    );

Widget _wrap(ProviderContainer container, String intentId) =>
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        home: TransactionDetailScreen(id: intentId),
      ),
    );

void main() {
  late MockTransactionRepository mockRepo;
  late MockPaymentCacheStore mockCache;

  setUp(() {
    mockRepo = MockTransactionRepository();
    mockCache = MockPaymentCacheStore();
    when(mockCache.readHistoryPage(any)).thenAnswer((_) async => null);
  });

  ProviderContainer _container() => ProviderContainer(overrides: [
        transactionRepositoryProvider.overrideWithValue(mockRepo),
        paymentCacheStoreProvider.overrideWithValue(mockCache),
      ]);

  // ─── T046: SC-04 — 3 mandatory status rows present ───────────────────────────

  testWidgets('SC-04: Capture, Accounting, Service rows always rendered',
      (tester) async {
    final item = _makeItem();
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(PaymentStatusRow, 'Capture'), findsOneWidget);
    expect(find.widgetWithText(PaymentStatusRow, 'Accounting'), findsOneWidget);
    expect(find.widgetWithText(PaymentStatusRow, 'Service'), findsOneWidget);
    // No refund row when refundStatus is null
    expect(find.widgetWithText(PaymentStatusRow, 'Refund'), findsNothing);
  });

  testWidgets('SC-04: Refund status row appears when refundStatus is non-null',
      (tester) async {
    final item = _makeItem(refundStatus: RefundStatus.pending);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(PaymentStatusRow, 'Refund'), findsOneWidget);
  });

  // ─── T046: SC-05 — deep link tile rendered correctly ─────────────────────────

  testWidgets('SC-05: AvailableLink shows tappable list tile', (tester) async {
    const link = AvailableLink(url: 'https://example.com/domain', label: 'View Domain');
    final item = _makeItem(deepLink: link);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('View Domain'), findsOneWidget);
  });

  testWidgets('SC-05: UnavailableLink shows greyed unavailable tile',
      (tester) async {
    const link = UnavailableLink(reason: 'module_unavailable');
    final item = _makeItem(deepLink: link);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('Unavailable'), findsOneWidget);
  });

  // ─── T046: providerReference masked ──────────────────────────────────────────

  testWidgets('providerReference is masked — last 4 digits only', (tester) async {
    final item = _makeItem(providerReference: 'ABCD12345678');
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    // Full reference must NOT appear
    expect(find.text('ABCD12345678'), findsNothing);
    // Masked form (****5678) must appear
    expect(find.text('****5678'), findsOneWidget);
  });

  // ─── T046: receipt button ─────────────────────────────────────────────────────

  testWidgets('View Receipt button present when receiptAvailable=true',
      (tester) async {
    final item = _makeItem(receiptAvailable: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('View Receipt'), findsOneWidget);
  });

  testWidgets('View Receipt button absent when receiptAvailable=false',
      (tester) async {
    final item = _makeItem(receiptAvailable: false);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('View Receipt'), findsNothing);
  });

  // ─── T069: SC-08 — refund button conditional on refundEligible ───────────────

  testWidgets('SC-08: Request Refund button present when refundEligible=true',
      (tester) async {
    final item = _makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('Request Refund'), findsOneWidget);
  });

  testWidgets('SC-08: Request Refund button absent when refundEligible=false',
      (tester) async {
    final item = _makeItem(refundEligible: false);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    expect(find.text('Request Refund'), findsNothing);
  });

  testWidgets('SC-08: tapping Request Refund shows refund confirm UI',
      (tester) async {
    final item = _makeItem(refundEligible: true);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Request Refund'));
    await tester.pumpAndSettle();

    expect(find.text('Request Refund'), findsOneWidget); // still visible in confirm UI
    expect(find.text('Reason for refund'), findsOneWidget);
    expect(find.text('Submit Refund Request'), findsOneWidget);
  });

  testWidgets('SC-08: refundStatus=pending shows Refund status row (in progress)',
      (tester) async {
    final item =
        _makeItem(refundEligible: true, refundStatus: RefundStatus.pending);
    when(mockRepo.fetchDetail(any)).thenAnswer((_) async => item);

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container, 'intent-1'));
    await tester.pumpAndSettle();

    // Refund row visible showing pending state
    expect(find.widgetWithText(PaymentStatusRow, 'Refund'), findsOneWidget);
  });
}
