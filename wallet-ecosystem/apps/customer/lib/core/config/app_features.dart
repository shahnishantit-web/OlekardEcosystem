/// Compile-time feature switches for local builds.
abstract final class AppFeatures {
  /// Disable Firebase initialization and push registration for local demos with
  /// `--dart-define=ENABLE_FIREBASE=false`.
  static const enableFirebase = bool.fromEnvironment(
    'ENABLE_FIREBASE',
    defaultValue: true,
  );
}
