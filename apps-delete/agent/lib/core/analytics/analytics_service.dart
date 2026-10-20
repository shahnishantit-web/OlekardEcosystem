// Replace NoOpAnalyticsService by overriding analyticsServiceProvider in the
// app's ProviderScope when a vendor is selected.
abstract class AnalyticsService {
  void trackEvent(String name, {Map<String, Object>? properties});
  void trackScreen(String screenName);
  void setUserProperties(Map<String, Object> properties);
}

class NoOpAnalyticsService implements AnalyticsService {
  @override
  void trackEvent(String name, {Map<String, Object>? properties}) {}

  @override
  void trackScreen(String screenName) {}

  @override
  void setUserProperties(Map<String, Object> properties) {}
}
