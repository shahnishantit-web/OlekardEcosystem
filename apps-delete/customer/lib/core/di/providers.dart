import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../analytics/analytics_service.dart';
import '../../modules/auth/services/auth_api_service.dart';
import '../../modules/auth/state/auth_state_notifier.dart';
import '../../modules/auth/storage/auth_token_storage.dart';

export '../../modules/auth/state/auth_state_notifier.dart'
    show authStateNotifierProvider;

/// Secure token storage — Keychain (iOS) / Keystore (Android). BR-009.
/// Override in tests with an in-memory mock.
final secureTokenStorageProvider = Provider<AuthTokenStorage>((ref) {
  return SecureAuthTokenStorage();
});

/// HTTP client — wired to secure token storage so AuthInterceptor can inject
/// Bearer tokens on every request automatically.
final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.read(secureTokenStorageProvider);
  return ApiClient(getToken: () => storage.getAccessToken());
});

/// Override declared in auth_state_notifier.dart — bound here to the real impl.
/// Allows widget tests to inject a mock storage without touching ProviderScope.
final authTokenStorageOverride = authTokenStorageProvider
    .overrideWith((ref) => ref.read(secureTokenStorageProvider));

/// Auth API service override — uses the production ApiClient.
final authApiServiceOverride = authApiServiceProvider
    .overrideWith((ref) => AuthApiService(ref.read(apiClientProvider)));

/// Convenience list of root-level provider overrides.
/// Apply in main.dart: `ProviderScope(overrides: rootProviderOverrides, child: ...)`.
final rootProviderOverrides = [
  authTokenStorageOverride,
  authApiServiceOverride,
];

/// Analytics provider — NoOp by default; override at E31/E32.
final analyticsServiceProvider = Provider<AnalyticsService>(
  (ref) => NoOpAnalyticsService(),
);
