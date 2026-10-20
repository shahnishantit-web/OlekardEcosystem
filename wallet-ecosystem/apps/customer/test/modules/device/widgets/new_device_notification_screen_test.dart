import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/theme/app_theme.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/domain/repositories/device_repository.dart';
import 'package:wallet_customer/modules/device/presentation/device_list_providers.dart';
import 'package:wallet_customer/modules/device/presentation/screens/new_device_notification_screen.dart';

class _StubRepo implements DeviceRepository {
  bool acknowledgeCalled = false;
  bool? lastAcknowledged;

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
  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged}) async {
    acknowledgeCalled = true;
    lastAcknowledged = acknowledged;
  }
}

Device _testDevice() => Device(
      id: 'device-1',
      userId: 'user-1',
      deviceName: 'New Phone',
      platform: 'android',
      status: DeviceStatus.pendingVerification,
      createdAt: DateTime(2026, 1, 1),
    );

void main() {
  group('NewDeviceNotificationScreen', () {
    testWidgets('shows both CTAs', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
          child: MaterialApp(theme: AppTheme.dark, home: NewDeviceNotificationScreen(device: _testDevice())),
        ),
      );

      expect(find.text('Yes, this was me'), findsOneWidget);
      expect(find.text('No, block this device'), findsOneWidget);
    });

    testWidgets('shows device name in card', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
          child: MaterialApp(theme: AppTheme.dark, home: NewDeviceNotificationScreen(device: _testDevice())),
        ),
      );

      expect(find.text('New Phone'), findsOneWidget);
    });

    testWidgets('"Yes, this was me" calls acknowledgeNewDevice with true', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
          child: MaterialApp(theme: AppTheme.dark, home: NewDeviceNotificationScreen(device: _testDevice())),
        ),
      );

      await tester.tap(find.text('Yes, this was me'));
      await tester.pumpAndSettle();

      expect(repo.acknowledgeCalled, isTrue);
      expect(repo.lastAcknowledged, isTrue);
    });

    testWidgets('"No, block this device" calls acknowledgeNewDevice with false', (tester) async {
      final repo = _StubRepo();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
          child: MaterialApp(theme: AppTheme.dark, home: NewDeviceNotificationScreen(device: _testDevice())),
        ),
      );

      await tester.tap(find.text('No, block this device'));
      await tester.pumpAndSettle();

      expect(repo.acknowledgeCalled, isTrue);
      expect(repo.lastAcknowledged, isFalse);
    });

    testWidgets('shows loading indicator while action is in-flight', (tester) async {
      final repo = _DelayedStubRepo();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [deviceRepositoryProvider.overrideWithValue(repo)],
          child: MaterialApp(theme: AppTheme.dark, home: NewDeviceNotificationScreen(device: _testDevice())),
        ),
      );

      await tester.tap(find.text('Yes, this was me'));
      await tester.pump(); // Trigger rebuild without settling

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });
  });
}

class _DelayedStubRepo implements DeviceRepository {
  @override
  Future<({List<Device> devices, int total, int currentPage, int lastPage})>
      listDevices({int page = 1, int perPage = 15}) async =>
          (devices: <Device>[], total: 0, currentPage: 1, lastPage: 1);

  @override
  Future<Device> getDevice(String deviceId) async => Device(
        id: deviceId,
        userId: 'u1',
        deviceName: 'x',
        platform: 'android',
        status: DeviceStatus.trusted,
        createdAt: DateTime(2026),
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
  Future<void> acknowledgeNewDevice(String deviceId, {required bool acknowledged}) =>
      Completer<void>().future; // never resolves — keeps loading state
}
