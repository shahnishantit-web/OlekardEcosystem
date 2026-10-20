enum AppEnvironment { development, test, production }

extension AppEnvironmentX on AppEnvironment {
  static AppEnvironment fromDartDefine() {
    const raw = String.fromEnvironment('APP_ENV', defaultValue: 'development');
    return switch (raw) {
      'production' => AppEnvironment.production,
      'test' => AppEnvironment.test,
      _ => AppEnvironment.development,
    };
  }

  bool get isProduction => this == AppEnvironment.production;
  bool get isDevelopment => this == AppEnvironment.development;
}
