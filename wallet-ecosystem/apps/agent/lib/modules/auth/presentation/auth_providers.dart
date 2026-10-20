import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/notifications/push_notification_service.dart';
import 'package:wallet_agent/core/security/secure_storage_service.dart';
import 'package:wallet_agent/modules/auth/data/repositories/auth_repository_impl.dart';
import 'package:wallet_agent/modules/auth/domain/entities/agent.dart';
import 'package:wallet_agent/modules/auth/domain/repositories/auth_repository.dart';

enum AuthStateEnum { unknown, unauthenticated, authenticated, requiresReauth }

class AuthState {
  final AuthStateEnum status;
  final Agent? agent;
  final String? otpReference;
  final String? challengeToken;

  const AuthState({
    required this.status,
    this.agent,
    this.otpReference,
    this.challengeToken,
  });

  AuthState copyWith({
    AuthStateEnum? status,
    Agent? agent,
    String? otpReference,
    String? challengeToken,
  }) {
    return AuthState(
      status: status ?? this.status,
      agent: agent ?? this.agent,
      otpReference: otpReference ?? this.otpReference,
      challengeToken: challengeToken ?? this.challengeToken,
    );
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  final storage = ref.watch(secureStorageServiceProvider);
  final dio = createDio(null);
  return AuthRepositoryImpl(dio: dio, storage: storage);
});

class AuthNotifier extends StateNotifier<AuthState> {
  final AuthRepository _repository;
  final SecureStorageService _storage;

  AuthNotifier({
    required AuthRepository repository,
    required SecureStorageService storage,
  })  : _repository = repository,
        _storage = storage,
        super(const AuthState(status: AuthStateEnum.unknown)) {
    _initialize();
  }

  Future<void> _initialize() async {
    try {
      final token = await _storage.getAccessToken();
      if (token != null) {
        AuthInterceptor.updateCachedTokens(
          accessToken: token,
          refreshToken: (await _storage.getRefreshToken()) ?? '',
        );
        try {
          final agent = await _repository.getProfile();
          state = AuthState(status: AuthStateEnum.authenticated, agent: agent);
        } catch (_) {
          state = const AuthState(status: AuthStateEnum.unauthenticated);
        }
      } else {
        state = const AuthState(status: AuthStateEnum.unauthenticated);
      }
    } catch (_) {
      state = const AuthState(status: AuthStateEnum.unauthenticated);
    }
  }

  Future<void> login({required String phone, required String pinHash}) async {
    final result = await _repository.login(phone: phone, pinHash: pinHash);
    state = AuthState(
      status: AuthStateEnum.authenticated,
      agent: result.agent,
    );
  }

  Future<void> logout() async {
    await _repository.logout();
    state = const AuthState(status: AuthStateEnum.unauthenticated);
  }

  /// Alias for [logout] — used by ProfileScreen.
  Future<void> signOut() => logout();

  void requireReauth() {
    state = state.copyWith(status: AuthStateEnum.requiresReauth);
  }
}

final authNotifierProvider =
    StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  return AuthNotifier(
    repository: ref.watch(authRepositoryProvider),
    storage: ref.watch(secureStorageServiceProvider),
  );
});
