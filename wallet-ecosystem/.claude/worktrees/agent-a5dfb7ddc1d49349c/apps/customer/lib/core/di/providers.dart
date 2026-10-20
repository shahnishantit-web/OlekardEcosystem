import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../analytics/analytics_service.dart';
import '../security/secure_storage_service.dart';
import '../security/screenshot_guard.dart';

export '../../modules/auth/presentation/auth_providers.dart' show authProvider;

final apiClientProvider = Provider<ApiClient>((ref) {
  const baseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://localhost:8000');
  return ApiClient(baseUrl: baseUrl);
});

final analyticsServiceProvider = Provider<AnalyticsService>((ref) {
  return NoOpAnalyticsService();
});

final secureStorageProvider = Provider<SecureStorageService>(
  (_) => const SecureStorageService(),
);

final screenshotGuardProvider = Provider<ScreenshotGuard>(
  (_) => ScreenshotGuard(),
);
