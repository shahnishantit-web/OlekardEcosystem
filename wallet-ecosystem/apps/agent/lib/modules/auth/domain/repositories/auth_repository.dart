import 'package:wallet_agent/modules/auth/domain/entities/agent.dart';
import 'package:wallet_agent/modules/auth/domain/entities/auth_token.dart';

abstract class AuthRepository {
  Future<({AuthToken token, Agent agent})> login({
    required String phone,
    required String pinHash,
  });

  Future<void> logout();

  Future<AuthToken> refreshToken(String refreshToken);

  Future<({String otpReference, DateTime expiresAt})> forgotPin(String phone);

  Future<void> resetPin({
    required String otpReference,
    required String otpCode,
    required String newPinHash,
  });

  Future<void> changePin({
    required String currentPinHash,
    required String newPinHash,
  });

  Future<String> stepUp(String pinHash);

  Future<Agent> getProfile();
}
