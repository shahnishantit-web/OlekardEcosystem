/// Abstract analytics service.
///
/// Replace NoOpAnalyticsService by overriding analyticsServiceProvider
/// in the app's ProviderScope when a vendor is selected.
/// No analytics vendor selection — vendor is TBD.
abstract class AnalyticsService {
  void trackEvent(String name, {Map<String, Object>? properties});

  void trackScreen(String screenName);

  void setUserProperties(Map<String, Object> properties);
}

/// No-op implementation — no vendor calls, no network I/O.
class NoOpAnalyticsService implements AnalyticsService {
  @override
  void trackEvent(String name, {Map<String, Object>? properties}) {}

  @override
  void trackScreen(String screenName) {}

  @override
  void setUserProperties(Map<String, Object> properties) {}
}
