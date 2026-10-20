import '../../domain/entities/wallet.dart';

class WalletDto {
  static Wallet fromJson(Map<String, dynamic> json) {
    final statusStr = json['status'] as String? ?? 'active';
    final status = WalletStatus.values.firstWhere(
      (e) => e.name == statusStr,
      orElse: () => WalletStatus.active,
    );
    return Wallet(
      id: json['id'] as String,
      walletNumber: json['wallet_number'] as String,
      currencyCode: json['currency_code'] as String,
      balanceMinorUnits: json['balance_minor_units'] as int,
      status: status,
      updatedAt: DateTime.parse(json['updated_at'] as String),
    );
  }
}

class WalletLimitsDto {
  static WalletLimits fromJson(Map<String, dynamic> json) => WalletLimits(
        minFundMinorUnits: json['min_fund_minor_units'] as int,
        maxFundMinorUnits: json['max_fund_minor_units'] as int,
        maxDailyTransferMinorUnits: json['max_daily_transfer_minor_units'] as int,
        minWithdrawalMinorUnits: json['min_withdrawal_minor_units'] as int,
        dailyUsedMinorUnits: json['daily_used_minor_units'] as int,
      );
}
