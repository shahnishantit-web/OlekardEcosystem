import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter/foundation.dart';
import '../api/api_client.dart';
import '../analytics/analytics_service.dart';
import '../security/secure_storage_service.dart';
import '../security/screenshot_guard.dart';

export '../../modules/auth/presentation/auth_providers.dart' show authProvider;

final apiClientProvider = Provider<ApiClient>((ref) {
  const configuredUrl = String.fromEnvironment('API_BASE_URL');
  // Android's localhost is the emulator itself; 10.0.2.2 reaches the host API.
  // Physical devices and deployed environments supply API_BASE_URL explicitly.
  final baseUrl = configuredUrl.isNotEmpty
      ? configuredUrl
      : !kIsWeb && defaultTargetPlatform == TargetPlatform.android
          ? 'http://10.0.2.2:8000'
          : 'http://localhost:8000';
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
