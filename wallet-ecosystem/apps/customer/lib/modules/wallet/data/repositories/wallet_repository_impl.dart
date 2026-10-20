import 'package:dio/dio.dart';
import '../../domain/entities/wallet.dart';
import '../../domain/repositories/wallet_repository.dart';
import '../models/wallet_dto.dart';
import '../../../../core/api/api_client.dart';

class WalletRepositoryImpl implements WalletRepository {
  final Dio _dio;

  WalletRepositoryImpl({required ApiClient apiClient}) : _dio = apiClient.dio;

  @override
  Future<Wallet> getMyWallet() async {
    final res = await _dio.get('/v1/wallets/my');
    final wallet = res.data['data'] as Map<String, dynamic>;
    final pockets = (wallet['pockets'] as List).cast<Map<String, dynamic>>();
    if (pockets.isEmpty) {
      throw StateError('This wallet has no currency account. Contact support.');
    }
    final pocket = pockets.firstWhere((p) => p['currency_code'] == 'BWP',
        orElse: () => pockets.first);
    final balanceResponse = await _dio.get(
        '/v1/wallets/${wallet['id']}/pockets/${pocket['currency_code']}/balance');
    final balance = balanceResponse.data['data'] as Map<String, dynamic>;
    return WalletDto.fromJson({
      'id': wallet['id'],
      'wallet_number': wallet['wallet_reference'],
      'currency_code': pocket['currency_code'],
      'balance_minor_units': balance['available_balance'],
      'status': wallet['status'],
      'updated_at': balance['as_of_timestamp'],
      'precision': balance['precision'],
    });
  }

  @override
  Future<WalletLimits> getWalletLimits() async {
    final res = await _dio.get('/v1/customer/wallets/me/limits');
    return WalletLimitsDto.fromJson(res.data as Map<String, dynamic>);
  }

  @override
  Future<String> getReceiveQrData() async {
    final wallet = await getMyWallet();
    return wallet.walletNumber;
  }
}
