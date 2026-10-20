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
    final res = await _dio.get('/v1/customer/wallets/me');
    return WalletDto.fromJson(res.data as Map<String, dynamic>);
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
