import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:local_auth/local_auth.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const channel = MethodChannel('plugins.flutter.io/local_auth');
  final messenger =
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
  final calls = <MethodCall>[];
  late BiometricService service;
  var accepted = true;
  var deviceSupported = true;
  var enrolled = <String>['fingerprint'];
  var fail = false;

  setUp(() {
    calls.clear();
    accepted = true;
    deviceSupported = true;
    enrolled = ['fingerprint'];
    fail = false;
    service = BiometricService();
    messenger.setMockMethodCallHandler(channel, (call) async {
      calls.add(call);
      if (fail) throw PlatformException(code: 'LockedOut');
      return switch (call.method) {
        'authenticate' => accepted,
        'getAvailableBiometrics' => enrolled,
        'isDeviceSupported' => deviceSupported,
        _ => throw MissingPluginException(call.method),
      };
    });
  });

  tearDown(() => messenger.setMockMethodCallHandler(channel, null));

  test('preserves background retry, device PIN fallback and confirmation',
      () async {
    expect(await service.authenticate('Unlock wallet'), isTrue);
    final args = calls.single.arguments as Map<Object?, Object?>;
    expect(args['localizedReason'], 'Unlock wallet');
    expect(args['stickyAuth'], isTrue);
    expect(args['biometricOnly'], isFalse);
    expect(args['sensitiveTransaction'], isTrue);
  });

  test('cancellation and platform errors do not authenticate', () async {
    accepted = false;
    expect(await service.authenticate('Unlock wallet'), isFalse);
    fail = true;
    expect(await service.authenticate('Unlock wallet'), isFalse);
  });

  test('device credentials remain available without enrolled biometrics',
      () async {
    enrolled = [];
    expect(await service.isAvailable(), isTrue);
    deviceSupported = false;
    expect(await service.isAvailable(), isFalse);
  });

  test('unavailable hardware and enumeration errors fail closed', () async {
    fail = true;
    expect(await service.isAvailable(), isFalse);
    expect(await service.getAvailableBiometrics(), isEmpty);
  });

  test('enrolled biometrics are returned unchanged', () async {
    expect(await service.getAvailableBiometrics(), [BiometricType.fingerprint]);
  });
}
