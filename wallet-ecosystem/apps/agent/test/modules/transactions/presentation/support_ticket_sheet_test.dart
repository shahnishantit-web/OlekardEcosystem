// T089: Widget tests for agent SupportTicketDraftSheet
// ignore_for_file: type=lint
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_agent/modules/transactions/domain/entities/refund_request.dart';
import 'package:wallet_agent/modules/transactions/presentation/screens/support_ticket_sheet.dart';
import 'package:wallet_agent/modules/transactions/presentation/transaction_providers.dart';

import '../application/agent_transaction_test.mocks.dart';

const _intentId = 'AGENT-INTENT-001';
const _prefilled = 'Cash In for Customer';

Widget _wrap(ProviderContainer container) => UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        home: Scaffold(
          body: SupportTicketDraftSheet(
            paymentIntentId: _intentId,
            prefilledSubject: _prefilled,
          ),
        ),
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

  // ─── paymentIntentId shown as read-only reference ────────────────────────────

  testWidgets('paymentIntentId rendered as read-only Payment Reference field',
      (tester) async {
    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    expect(find.text(_intentId), findsOneWidget);
    expect(find.text('Payment Reference'), findsOneWidget);
  });

  // ─── subject pre-filled ──────────────────────────────────────────────────────

  testWidgets('subject field pre-filled with prefilledSubject', (tester) async {
    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    final subjectField = find.byKey(const Key('subject_field'));
    expect(subjectField, findsOneWidget);
    expect(
      (tester.widget<TextField>(subjectField).controller?.text),
      _prefilled,
    );
  });

  // ─── stable payment reference is pre-filled and stable ───────────────────────

  testWidgets('agent payment reference stable across rebuilds', (tester) async {
    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    // Reference must not change after rebuild
    expect(find.text(_intentId), findsOneWidget);
    await tester.pump();
    expect(find.text(_intentId), findsOneWidget);
  });

  // ─── submit calls createSupportTicket with paymentIntentId ───────────────────

  testWidgets('submit calls createSupportTicket with agent paymentIntentId',
      (tester) async {
    when(mockRepo.createSupportTicket(any)).thenAnswer((_) async {});

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('submit_button')));
    await tester.pumpAndSettle();

    final captured =
        verify(mockRepo.createSupportTicket(captureAny)).captured;
    expect(captured.length, 1);
    final draft = captured.first as SupportTicketDraft;
    expect(draft.paymentIntentId, _intentId);
    expect(draft.subject, _prefilled);
  });

  // ─── stable reference shown on success ───────────────────────────────────────

  testWidgets('stable reference shown after successful submission', (tester) async {
    when(mockRepo.createSupportTicket(any)).thenAnswer((_) async {});

    final container = _container();
    addTearDown(container.dispose);
    await tester.pumpWidget(_wrap(container));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('submit_button')));
    await tester.pumpAndSettle();

    expect(find.text('Ticket submitted'), findsOneWidget);
    expect(find.textContaining(_intentId), findsOneWidget);
  });
}
