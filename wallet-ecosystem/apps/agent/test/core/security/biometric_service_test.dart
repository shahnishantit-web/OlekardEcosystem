import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/core/security/biometric_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('plugins.flutter.io/local_auth');
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
  final calls = <MethodCall>[];
  var canCheckBiometrics = true;
  var deviceSupported = true;
  var authenticationAccepted = true;

  setUp(() {
    calls.clear();
    canCheckBiometrics = true;
    deviceSupported = true;
    authenticationAccepted = true;
    messenger.setMockMethodCallHandler(channel, (call) async {
      calls.add(call);
      return switch (call.method) {
        'getAvailableBiometrics' =>
          canCheckBiometrics ? ['fingerprint'] : <String>[],
        'isDeviceSupported' => deviceSupported,
        'authenticate' => authenticationAccepted,
        _ => throw MissingPluginException(call.method),
      };
    });
  });

  tearDown(() => messenger.setMockMethodCallHandler(channel, null));

  test('requires device support and an enrolled biometric', () async {
    final service = BiometricService();

    expect(await service.isAvailable(), isTrue);
    canCheckBiometrics = false;
    expect(await service.isAvailable(), isFalse);
    canCheckBiometrics = true;
    deviceSupported = false;
    expect(await service.isAvailable(), isFalse);
  });

  test('authentication result follows the platform response', () async {
    final service = BiometricService();

    expect(await service.authenticate(), isTrue);
    authenticationAccepted = false;
    expect(await service.authenticate(), isFalse);
  });
}
