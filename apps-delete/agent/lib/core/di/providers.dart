import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../analytics/analytics_service.dart';
import '../../modules/auth/services/auth_api_service.dart';
import '../../modules/auth/state/auth_state_notifier.dart';
import '../../modules/auth/storage/auth_token_storage.dart';
import '../../features/profile/services/agent_profile_api_service.dart';
import '../../features/profile/providers/agent_profile_state_notifier.dart';

export '../../modules/auth/state/auth_state_notifier.dart'
    show authStateNotifierProvider;

/// Secure token storage — Keychain (iOS) / Keystore (Android). BR-009.
final secureTokenStorageProvider = Provider<AuthTokenStorage>((ref) {
  return SecureAuthTokenStorage();
});

/// HTTP client wired to secure token storage for Bearer token injection.
final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.read(secureTokenStorageProvider);
  return ApiClient(getToken: () => storage.getAccessToken());
});

final authTokenStorageOverride = authTokenStorageProvider
    .overrideWith((ref) => ref.read(secureTokenStorageProvider));

final authApiServiceOverride = authApiServiceProvider
    .overrideWith((ref) => AuthApiService(ref.read(apiClientProvider)));

final agentProfileApiServiceOverride = agentProfileApiServiceProvider
    .overrideWith((ref) => AgentProfileApiService(ref.read(apiClientProvider)));

/// Convenience list of root-level provider overrides.
final rootProviderOverrides = [
  authTokenStorageOverride,
  authApiServiceOverride,
  agentProfileApiServiceOverride,
];

/// Analytics provider — NoOp by default; override at E31/E32.
final analyticsServiceProvider = Provider<AnalyticsService>(
  (ref) => NoOpAnalyticsService(),
);
