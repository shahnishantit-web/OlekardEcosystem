import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/domain/repositories/device_repository.dart';
import 'package:wallet_customer/modules/device/presentation/device_list_providers.dart';
import 'package:wallet_customer/modules/device/presentation/screens/device_list_screen.dart';

class _StubRepo implements DeviceRepository {
  final List<Device> _devices;
  final bool _throws;

  _StubRepo({List<Device>? devices, bool throws = false})
      : _devices = devices ?? [],
        _throws = throws;

  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})>
      listDevices({int page = 1, int perPage = 15}) async {
    if (_throws) throw Exception('network error');
    return (devices: _devices, total: _devices.length, currentPage: 1, lastPage: 1);
  }

  @override
  Future<Device> getDevice(String deviceId) async => _devices.first;

  @override
  Future<void> trustDevice(String deviceId, {String? reason}) async {}

  @override
  Future<void> removeDevice(String deviceId) async {
    _devices.removeWhere((d) => d.id == deviceId);
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

Device _makeDevice(String id, String name) => Device(
      id: id,
      userId: 'u1',
      deviceName: name,
      platform: 'android',
      status: DeviceStatus.trusted,
      lastSeenAt: DateTime.now().subtract(const Duration(hours: 1)),
      createdAt: DateTime(2026, 1, 1),
    );

Widget _wrap(DeviceRepository repo) => ProviderScope(
      overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(home: DeviceListScreen()),
    );

void main() {
  group('DeviceListScreen', () {
    testWidgets('shows loading indicator while fetching', (tester) async {
      // Delay the stub so loading state is visible
      final repo = _DelayedStubRepo();
      await tester.pumpWidget(_wrap(repo));
      // Before settling, loading indicator should be present
      expect(find.byType(CircularProgressIndicator), findsWidgets);
      await tester.pumpAndSettle();
    });

    testWidgets('shows empty state when device list is empty', (tester) async {
      await tester.pumpWidget(_wrap(_StubRepo()));
      await tester.pumpAndSettle();
      expect(find.text('No registered devices.'), findsOneWidget);
    });

    testWidgets('renders device tiles for each returned device', (tester) async {
      final devices = [
        _makeDevice('d1', 'Phone Alpha'),
        _makeDevice('d2', 'Tablet Beta'),
      ];
      await tester.pumpWidget(_wrap(_StubRepo(devices: devices)));
      await tester.pumpAndSettle();
      expect(find.text('Phone Alpha'), findsOneWidget);
      expect(find.text('Tablet Beta'), findsOneWidget);
    });

    testWidgets('shows retry button on load failure', (tester) async {
      await tester.pumpWidget(_wrap(_StubRepo(throws: true)));
      await tester.pumpAndSettle();
      expect(find.text('Failed to load devices.'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
    });
  });
}

/// Repo that never resolves (used to hold loading state in pump)
class _DelayedStubRepo implements DeviceRepository {
  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})>
      listDevices({int page = 1, int perPage = 15}) =>
          Completer<({List<Device> devices, int total, int currentPage, int lastPage})>().future;

  @override
  Future<Device> getDevice(String deviceId) => Future.value(
        Device(
          id: deviceId,
          userId: 'u1',
          deviceName: 'x',
          platform: 'android',
          status: DeviceStatus.trusted,
          createdAt: DateTime(2026),
        ),
      );

  @override
  Future<void> trustDevice(String deviceId, {String? reason}) async {}

  @override
  Future<void> removeDevice(String deviceId) async {}

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
