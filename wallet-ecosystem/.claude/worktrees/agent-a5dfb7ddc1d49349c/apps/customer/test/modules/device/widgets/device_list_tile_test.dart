import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/presentation/widgets/device_list_tile.dart';

void main() {
  Device makeDevice({
    DeviceStatus status = DeviceStatus.trusted,
    String platform = 'android',
    DateTime? lastSeenAt,
  }) {
    return Device(
      id: 'test-id',
      userId: 'user-1',
      deviceName: 'Test Phone',
      platform: platform,
      status: status,
      lastSeenAt: lastSeenAt ?? DateTime.now().subtract(const Duration(hours: 2)),
      createdAt: DateTime(2026, 1, 1),
    );
  }

  Widget wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

  group('DeviceListTile', () {
    testWidgets('shows device name and status badge', (tester) async {
      await tester.pumpWidget(
        wrap(DeviceListTile(device: makeDevice())),
      );
      expect(find.text('Test Phone'), findsOneWidget);
      expect(find.text('Trusted'), findsOneWidget);
    });

    testWidgets('shows phone_android icon for android platform', (tester) async {
      await tester.pumpWidget(
        wrap(DeviceListTile(device: makeDevice(platform: 'android'))),
      );
      expect(find.byIcon(Icons.phone_android), findsOneWidget);
    });

    testWidgets('shows phone_iphone icon for ios platform', (tester) async {
      await tester.pumpWidget(
        wrap(DeviceListTile(device: makeDevice(platform: 'ios'))),
      );
      expect(find.byIcon(Icons.phone_iphone), findsOneWidget);
    });

    testWidgets('shows Trust button when onTrust is provided', (tester) async {
      bool trusted = false;
      await tester.pumpWidget(
        wrap(DeviceListTile(
          device: makeDevice(),
          onTrust: () => trusted = true,
        )),
      );
      expect(find.text('Trust'), findsOneWidget);
      await tester.tap(find.text('Trust'));
      expect(trusted, isTrue);
    });

    testWidgets('shows Remove button when onRemove is provided', (tester) async {
      bool removed = false;
      await tester.pumpWidget(
        wrap(DeviceListTile(
          device: makeDevice(),
          onRemove: () => removed = true,
        )),
      );
      expect(find.text('Remove'), findsOneWidget);
      await tester.tap(find.text('Remove'));
      expect(removed, isTrue);
    });

    testWidgets('hides Trust/Remove buttons when callbacks are null', (tester) async {
      await tester.pumpWidget(
        wrap(DeviceListTile(device: makeDevice())),
      );
      expect(find.text('Trust'), findsNothing);
      expect(find.text('Remove'), findsNothing);
    });

    testWidgets('shows relative time for lastSeenAt', (tester) async {
      final device = makeDevice(lastSeenAt: DateTime.now().subtract(const Duration(days: 3)));
      await tester.pumpWidget(wrap(DeviceListTile(device: device)));
      expect(find.text('3d ago'), findsOneWidget);
    });

    testWidgets('calls onTap when tapped', (tester) async {
      bool tapped = false;
      await tester.pumpWidget(
        wrap(DeviceListTile(
          device: makeDevice(),
          onTap: () => tapped = true,
        )),
      );
      await tester.tap(find.byType(ListTile));
      expect(tapped, isTrue);
    });
  });
}
