import '../entities/funding_result.dart';

abstract interface class FundingRepository {
  Future<FundingResult> initiateFunding(FundRequestDto request);
  Future<Map<String, dynamic>> previewFunding({
    required int amountMinorUnits,
    required String currencyCode,
    required FundingMethod method,
  });
}
