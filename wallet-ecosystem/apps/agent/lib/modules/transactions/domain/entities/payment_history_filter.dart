import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';

class PaymentHistoryFilter {
  final PaymentPurposeType? purposeType;
  final PaymentStatus? status;
  final DateTime? dateFrom;
  final DateTime? dateTo;
  final String? currencyCode;
  final int page;
  final int perPage;

  const PaymentHistoryFilter({
    this.purposeType,
    this.status,
    this.dateFrom,
    this.dateTo,
    this.currencyCode,
    this.page = 1,
    this.perPage = 20,
  });

  PaymentHistoryFilter copyWith({
    PaymentPurposeType? purposeType,
    PaymentStatus? status,
    DateTime? dateFrom,
    DateTime? dateTo,
    String? currencyCode,
    int? page,
    int? perPage,
  }) {
    final filterChanged = purposeType != this.purposeType ||
        status != this.status ||
        dateFrom != this.dateFrom ||
        dateTo != this.dateTo ||
        currencyCode != this.currencyCode;

    return PaymentHistoryFilter(
      purposeType: purposeType ?? this.purposeType,
      status: status ?? this.status,
      dateFrom: dateFrom ?? this.dateFrom,
      dateTo: dateTo ?? this.dateTo,
      currencyCode: currencyCode ?? this.currencyCode,
      page: filterChanged ? 1 : (page ?? this.page),
      perPage: perPage ?? this.perPage,
    );
  }

  PaymentHistoryFilter nextPage() => PaymentHistoryFilter(
        purposeType: purposeType,
        status: status,
        dateFrom: dateFrom,
        dateTo: dateTo,
        currencyCode: currencyCode,
        page: page + 1,
        perPage: perPage,
      );

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PaymentHistoryFilter &&
          purposeType == other.purposeType &&
          status == other.status &&
          dateFrom == other.dateFrom &&
          dateTo == other.dateTo &&
          currencyCode == other.currencyCode &&
          page == other.page &&
          perPage == other.perPage;

  @override
  int get hashCode => Object.hash(
      purposeType, status, dateFrom, dateTo, currencyCode, page, perPage);
}
