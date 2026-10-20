import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/domain/repositories/device_repository.dart';
import 'package:wallet_customer/modules/device/presentation/device_list_providers.dart';
import 'package:wallet_customer/modules/device/presentation/screens/device_list_screen.dart';

// Stub repository for integration tests
class _StubDeviceRepository implements DeviceRepository {
  final List<Device> devices;

  _StubDeviceRepository(this.devices);

  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})>
      listDevices({int page = 1, int perPage = 15}) async {
    return (
      devices: devices,
      total: devices.length,
      currentPage: 1,
      lastPage: 1,
    );
  }

  @override
  Future<Device> getDevice(String deviceId) async {
    return devices.firstWhere((d) => d.id == deviceId);
  }

  @override
  Future<void> trustDevice(String deviceId, {String? reason}) async {}

  @override
  Future<void> removeDevice(String deviceId) async {
    devices.removeWhere((d) => d.id == deviceId);
  }

  @override
  Future<void> blockDevice(String deviceId, {String? reason}) async {}

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

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  final testDevices = [
    Device(
      id: 'device-1',
      userId: 'user-1',
      deviceName: 'My Android',
      platform: 'android',
      status: DeviceStatus.trusted,
      lastSeenAt: DateTime.now().subtract(const Duration(hours: 1)),
      createdAt: DateTime(2026, 1, 1),
    ),
    Device(
      id: 'device-2',
      userId: 'user-1',
      deviceName: 'Old iPhone',
      platform: 'ios',
      status: DeviceStatus.pendingVerification,
      lastSeenAt: DateTime.now().subtract(const Duration(days: 5)),
      createdAt: DateTime(2026, 2, 1),
    ),
  ];

  Widget testApp(List<Device> devices) {
    return ProviderScope(
      overrides: [
        deviceRepositoryProvider.overrideWithValue(_StubDeviceRepository(devices)),
      ],
      child: const MaterialApp(home: DeviceListScreen()),
    );
  }

  group('Device Management integration', () {
    testWidgets('device list renders all devices', (tester) async {
      await tester.pumpWidget(testApp(List.from(testDevices)));
      await tester.pumpAndSettle();

      expect(find.text('My Android'), findsOneWidget);
      expect(find.text('Old iPhone'), findsOneWidget);
      expect(find.text('Trusted'), findsOneWidget);
      expect(find.text('Pending Verification'), findsOneWidget);
    });

    testWidgets('Trust button shown only for pendingVerification device', (tester) async {
      await tester.pumpWidget(testApp(List.from(testDevices)));
      await tester.pumpAndSettle();

      // Trusted device: no Trust button
      // PendingVerification device: Trust button present
      expect(find.text('Trust'), findsOneWidget);
    });

    testWidgets('shows empty state when no devices', (tester) async {
      await tester.pumpWidget(testApp([]));
      await tester.pumpAndSettle();

      expect(find.text('No registered devices.'), findsOneWidget);
    });

    testWidgets('shows error retry button on load failure', (tester) async {
      // Override provider to throw
      final app = ProviderScope(
        overrides: [
          deviceListProvider.overrideWith(
            (ref, page) => throw Exception('Network error'),
          ),
        ],
        child: const MaterialApp(home: DeviceListScreen()),
      );
      await tester.pumpWidget(app);
      await tester.pumpAndSettle();

      expect(find.text('Failed to load devices.'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });

    testWidgets('device detail screen accessible from list tap', (tester) async {
      await tester.pumpWidget(testApp(List.from(testDevices)));
      await tester.pumpAndSettle();

      await tester.tap(find.text('My Android'));
      await tester.pumpAndSettle();

      expect(find.text('Device Details'), findsOneWidget);
      expect(find.text('My Android'), findsOneWidget);
    });
  });
}
