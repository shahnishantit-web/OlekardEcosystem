import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/domain/repositories/device_repository.dart';
import 'package:wallet_customer/modules/device/presentation/device_list_providers.dart';
import 'package:wallet_customer/modules/device/presentation/screens/block_device_confirmation_screen.dart';

class _StubRepo implements DeviceRepository {
  bool blockDeviceCalled = false;

  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})>
      listDevices({int page = 1, int perPage = 15}) async =>
          (devices: <Device>[], total: 0, currentPage: 1, lastPage: 1);

  @override
  Future<Device> getDevice(String deviceId) async => _testDevice();

  @override
  Future<void> trustDevice(String deviceId, {String? reason}) async {}

  @override
  Future<void> removeDevice(String deviceId) async {}

  @override
  Future<void> blockDevice(String deviceId, {String? reason}) async {
    blockDeviceCalled = true;
  }

  @override
  Future<String> generateFingerprint({
    required String platform,
    required Map<String, dynamic> attributes,
  }) async => 'fingerprint-hash';

  @override
  Future<({String deviceId, String status, String? challengeId})> registerDevice({
    required String deviceName,
    required String platform,
    required String fingerprintHash,
    required String idempotencyKey,
  }) async => (deviceId: 'device-stub', status: 'pending', challengeId: null);

  @override
  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged}) async {}
}

Device _testDevice() => Device(
      id: 'device-1',
      userId: 'user-1',
      deviceName: 'Suspicious Phone',
      platform: 'android',
      status: DeviceStatus.trusted,
      createdAt: DateTime(2026, 1, 1),
    );

Widget _wrap(_StubRepo repo) => ProviderScope(
      overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
      child: MaterialApp(home: BlockDeviceConfirmationScreen(device: _testDevice())),
    );

void main() {
  group('BlockDeviceConfirmationScreen', () {
    testWidgets('shows device name in title', (tester) async {
      await tester.pumpWidget(_wrap(_StubRepo()));
      expect(find.textContaining('Suspicious Phone'), findsWidgets);
    });

    testWidgets('shows Cancel and Confirm Block buttons', (tester) async {
      await tester.pumpWidget(_wrap(_StubRepo()));
      expect(find.text('Confirm Block'), findsOneWidget);
      expect(find.text('Cancel'), findsOneWidget);
    });

    testWidgets('Cancel does NOT call blockDevice', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(_wrap(repo));

      await tester.tap(find.text('Cancel'));
      await tester.pumpAndSettle();

      expect(repo.blockDeviceCalled, isFalse);
    });

    testWidgets('Confirm Block calls blockDevice', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(_wrap(repo));

      await tester.tap(find.text('Confirm Block'));
      await tester.pumpAndSettle();

      expect(repo.blockDeviceCalled, isTrue);
    });

    testWidgets('shows optional reason text field', (tester) async {
      await tester.pumpWidget(_wrap(_StubRepo()));
      expect(find.byType(TextField), findsOneWidget);
    });
  });
}
