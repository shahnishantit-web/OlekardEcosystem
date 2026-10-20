import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/auth_api_service.dart';
import '../state/auth_state.dart';
import '../state/auth_state_notifier.dart';
import '../storage/auth_token_storage.dart';

/// Proactively refreshes the access token before expiry when the app resumes.
/// On refresh failure, transitions the auth state to [AuthUnauthenticated].
///
/// Call [onAppResume] from the app lifecycle observer whenever the app
/// comes back to the foreground.
class TokenRefreshService {
  const TokenRefreshService(this._ref);

  final Ref _ref;

  AuthApiService get _api => _ref.read(authApiServiceProvider);
  AuthTokenStorage get _storage => _ref.read(authTokenStorageProvider);
  AuthStateNotifier get _notifier =>
      _ref.read(authStateNotifierProvider.notifier);

  /// Called on app resume. If the current state is authenticated, attempt
  /// a proactive token refresh if the stored token is available.
  Future<void> onAppResume() async {
    final authState = _ref.read(authStateNotifierProvider).valueOrNull;
    if (authState is! AuthAuthenticated) return;

    final refreshToken = await _storage.getRefreshToken();
    if (refreshToken == null || refreshToken.isEmpty) {
      // No refresh token stored — clear state and force re-login.
      await _notifier.logout();
      return;
    }

    try {
      final tokens = await _api.refreshToken(refreshToken);
      await _storage.saveTokens(
        accessToken: tokens.accessToken,
        refreshToken: tokens.refreshToken,
        sessionId: tokens.sessionId,
      );
    } on AuthApiException catch (e) {
      // Refresh token revoked, reused, or expired — force re-login.
      // IDENTITY_009 (revoked), IDENTITY_015 (reuse), IDENTITY_008 (expired).
      await _storage.clearTokens();
      _ref
          .read(authStateNotifierProvider.notifier)
          // Notify state directly without the network call since it already failed.
          .state = AsyncData(
        AuthUnauthenticated(errorCode: e.code, errorMessage: e.message),
      );
    }
  }
}

final tokenRefreshServiceProvider = Provider<TokenRefreshService>((ref) {
  return TokenRefreshService(ref);
});
