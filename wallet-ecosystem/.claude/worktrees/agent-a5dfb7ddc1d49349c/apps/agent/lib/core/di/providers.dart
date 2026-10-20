import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../api/api_client.dart';
import '../analytics/analytics_service.dart';
import '../auth/auth_provider.dart';

export '../auth/auth_provider.dart' show authProvider;

final apiClientProvider = Provider<ApiClient>((ref) {
  const baseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://localhost:8080');
  return ApiClient(baseUrl: baseUrl);
});

/// All providers are override-able for tests.
final analyticsServiceProvider = Provider<AnalyticsService>((ref) {
  return NoOpAnalyticsService();
});
