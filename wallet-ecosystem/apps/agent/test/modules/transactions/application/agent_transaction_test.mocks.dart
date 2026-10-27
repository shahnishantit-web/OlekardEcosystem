// ignore_for_file: type=lint
// ignore_for_file: override_on_non_overriding_member
// ignore_for_file: argument_type_not_assignable
import 'dart:async' as _i2;
import 'package:mockito/mockito.dart' as _i1;
import 'package:wallet_agent/core/api/api_response.dart' as _i3;
import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart'
    as _i7;
import 'package:wallet_agent/modules/transactions/domain/entities/payment_history_filter.dart'
    as _i4;
import 'package:wallet_agent/modules/transactions/domain/entities/payment_receipt.dart'
    as _i5;
import 'package:wallet_agent/modules/transactions/domain/entities/refund_request.dart'
    as _i6;
import 'package:wallet_agent/modules/transactions/domain/repositories/transaction_repository.dart'
    as _i8;
import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart'
    as _i9;

class MockTransactionRepository extends _i1.Mock
    implements _i8.TransactionRepository {
  MockTransactionRepository() {
    _i1.throwOnMissingStub(this);
  }

  @override
  _i2.Future<_i3.PaginatedResult<_i7.PaymentHistoryItem>> fetchHistory(
    _i4.PaymentHistoryFilter? filter,
  ) =>
      (super.noSuchMethod(
        Invocation.method(#fetchHistory, [filter]),
        returnValue: _i2.Future<_i3.PaginatedResult<_i7.PaymentHistoryItem>>.value(
          const _i3.PaginatedResult<_i7.PaymentHistoryItem>(
            data: [],
            currentPage: 1,
            total: 0,
            lastPage: 1,
          ),
        ),
      ) as _i2.Future<_i3.PaginatedResult<_i7.PaymentHistoryItem>>);

  @override
  _i2.Future<_i7.PaymentHistoryItem> fetchDetail(String? intentId) =>
      (super.noSuchMethod(
        Invocation.method(#fetchDetail, [intentId]),
        returnValue: _i2.Future<_i7.PaymentHistoryItem>.value(
          _i7.PaymentHistoryItem(
            id: 'id',
            referenceNumber: 'ref',
            purposeType: _i7.PaymentPurposeType.walletTransfer,
            status: _i7.PaymentStatus.completed,
            amountMinorUnits: 0,
            feeMinorUnits: 0,
            currencyCode: 'BWP',
            createdAt: DateTime(2026),
            captureStatus: _i7.CaptureStatus.captured,
            accountingStatus: _i7.AccountingStatus.posted,
            serviceStatus: _i7.ServiceStatus.confirmed,
            refundEligible: false,
            receiptAvailable: false,
            direction: _i7.PaymentDirection.debit,
          ),
        ),
      ) as _i2.Future<_i7.PaymentHistoryItem>);

  @override
  _i2.Future<_i5.PaymentReceipt> fetchReceipt(String? intentId) =>
      (super.noSuchMethod(
        Invocation.method(#fetchReceipt, [intentId]),
        returnValue: _i2.Future<_i5.PaymentReceipt>.value(
          _i5.PaymentReceipt(
            intentId: 'id',
            referenceNumber: 'ref',
            issuedAt: DateTime.fromMillisecondsSinceEpoch(0),
            totalAmountMinorUnits: 0,
            feeMinorUnits: 0,
            currencyCode: 'BWP',
            lineItems: [],
          ),
        ),
      ) as _i2.Future<_i5.PaymentReceipt>);

  @override
  _i2.Future<_i5.ReceiptDownloadToken> requestDownloadToken(
          String? intentId) =>
      (super.noSuchMethod(
        Invocation.method(#requestDownloadToken, [intentId]),
        returnValue: _i2.Future<_i5.ReceiptDownloadToken>.value(
          _i5.ReceiptDownloadToken(
            token: 'tok',
            downloadUrl: 'https://example.com/receipt.pdf',
            expiresAt: DateTime(2099),
          ),
        ),
      ) as _i2.Future<_i5.ReceiptDownloadToken>);

  @override
  _i2.Future<void> submitRefund(_i6.RefundRequest? request) =>
      (super.noSuchMethod(
        Invocation.method(#submitRefund, [request]),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<void> createSupportTicket(_i6.SupportTicketDraft? draft) =>
      (super.noSuchMethod(
        Invocation.method(#createSupportTicket, [draft]),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);
}

class MockPaymentCacheStore extends _i1.Mock implements _i9.PaymentCacheStore {
  MockPaymentCacheStore() {
    _i1.throwOnMissingStub(this);
  }

  @override
  _i2.Future<void> writeHistoryPage(
    String? userId,
    String? rawJson, {
    int? staleAfterSeconds,
  }) =>
      (super.noSuchMethod(
        Invocation.method(
          #writeHistoryPage,
          [userId, rawJson],
          {#staleAfterSeconds: staleAfterSeconds},
        ),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<_i9.PaymentCacheEntry?> readHistoryPage(String? userId) =>
      (super.noSuchMethod(
        Invocation.method(#readHistoryPage, [userId]),
        returnValue: _i2.Future<_i9.PaymentCacheEntry?>.value(null),
      ) as _i2.Future<_i9.PaymentCacheEntry?>);

  @override
  _i2.Future<void> writeDetail(
    String? intentId,
    String? rawJson, {
    int? staleAfterSeconds,
  }) =>
      (super.noSuchMethod(
        Invocation.method(
          #writeDetail,
          [intentId, rawJson],
          {#staleAfterSeconds: staleAfterSeconds},
        ),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<_i9.PaymentCacheEntry?> readDetail(String? intentId) =>
      (super.noSuchMethod(
        Invocation.method(#readDetail, [intentId]),
        returnValue: _i2.Future<_i9.PaymentCacheEntry?>.value(null),
      ) as _i2.Future<_i9.PaymentCacheEntry?>);

  @override
  _i2.Future<void> clearAll() =>
      (super.noSuchMethod(
        Invocation.method(#clearAll, []),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);
}
