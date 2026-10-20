import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/di/providers.dart';

void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  test('Android development API reaches the host without adb forwarding', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    final container = ProviderContainer();
    addTearDown(container.dispose);
    expect(container.read(apiClientProvider).dio.options.baseUrl,
        'http://10.0.2.2:8000');
  });

  test('desktop development keeps the loopback endpoint', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;
    final container = ProviderContainer();
    addTearDown(container.dispose);
    expect(container.read(apiClientProvider).dio.options.baseUrl,
        'http://localhost:8000');
  });
}
