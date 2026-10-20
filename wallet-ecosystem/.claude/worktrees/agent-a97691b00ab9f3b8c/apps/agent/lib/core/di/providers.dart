import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/analytics/analytics_service.dart';

/// All providers are override-able for tests.
final analyticsServiceProvider = Provider<AnalyticsService>((ref) {
  return NoOpAnalyticsService();
});
