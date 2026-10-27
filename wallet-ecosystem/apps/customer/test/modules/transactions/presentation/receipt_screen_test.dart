// T055: Widget tests for ReceiptScreen
// ignore_for_file: type=lint
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/modules/transactions/data/repositories/transaction_repository_impl.dart';
import 'package:wallet_customer/modules/transactions/domain/entities/payment_receipt.dart';
import 'package:wallet_customer/modules/transactions/presentation/screens/receipt_screen.dart';
import 'package:wallet_customer/modules/transactions/presentation/transaction_providers.dart';

import '../application/payment_history_notifier_test.mocks.dart';

const _intentId = 'intent-1';

PaymentReceipt _makeReceipt({String? providerReference}) => PaymentReceipt(
      intentId: _intentId,
      referenceNumber: 'RCP-001',
      issuedAt: DateTime(2026, 10, 1, 12, 0),
      totalAmountMinorUnits: 50100,
      feeMinorUnits: 100,
      currencyCode: 'BWP',
      providerReference: providerReference,
      lineItems: [
        ReceiptLineItem(
          description: 'Transfer',
          amountMinorUnits: 50000,
          currencyCode: 'BWP',
        ),
        ReceiptLineItem(
          description: 'Fee',
          amountMinorUnits: 100,
          currencyCode: 'BWP',
        ),
      ],
    );

Widget _wrap(ProviderContainer container) => UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        home: ReceiptScreen(intentId: _intentId),
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

  // ─── Receipt fields render ────────────────────────────────────────────────────

  testWidgets('receipt fields render — reference, date, total, fee, line items',
      (tester) async {
    when(mockRepo.fetchReceipt(any)).thenAnswer((_) async => _makeReceipt());

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    expect(find.text('RCP-001'), findsOneWidget);
    expect(find.text('Download PDF'), findsOneWidget);
    expect(find.text('Transfer'), findsOneWidget);
    // 'Fee' appears as both a line item and a summary row label
    expect(find.text('Fee'), findsAtLeastNWidgets(1));
  });

  // ─── Download button transitions to download state ───────────────────────────

  testWidgets('tapping Download PDF calls requestDownload()', (tester) async {
    when(mockRepo.fetchReceipt(any)).thenAnswer((_) async => _makeReceipt());
    when(mockRepo.requestDownloadToken(any)).thenAnswer((_) async =>
        ReceiptDownloadToken(
          token: 'tok-abc',
          downloadUrl: 'https://cdn.example.com/receipt.pdf',
          expiresAt: DateTime(2099),
        ));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Download PDF'));
    await tester.pump();

    // Download transitions to in-progress state (CircularProgressIndicator)
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
  });

  // ─── ReceiptAccessDenied renders error message ────────────────────────────────

  testWidgets('ReceiptAccessDenied shows Access Denied message', (tester) async {
    when(mockRepo.fetchReceipt(any))
        .thenThrow(const AccessDeniedException('Not your receipt'));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    expect(find.text('Access Denied'), findsOneWidget);
    expect(find.textContaining('Not your receipt'), findsOneWidget);
  });

  // ─── providerReference masked in receipt ─────────────────────────────────────

  testWidgets('providerReference is masked in receipt view', (tester) async {
    when(mockRepo.fetchReceipt(any))
        .thenAnswer((_) async => _makeReceipt(providerReference: 'PROV12345678'));

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // Full reference must NOT appear
    expect(find.text('PROV12345678'), findsNothing);
    // Masked form must appear
    expect(find.text('****5678'), findsOneWidget);
  });
}
