import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/device/domain/entities/device.dart';
import 'package:wallet_customer/modules/device/presentation/widgets/device_status_badge.dart';

void main() {
  Widget wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

  group('DeviceStatusBadge', () {
    testWidgets('shows Trusted label with green color', (tester) async {
      await tester.pumpWidget(wrap(const DeviceStatusBadge(status: DeviceStatus.trusted)));
      expect(find.text('Trusted'), findsOneWidget);
      final container = tester.widget<Container>(find.byType(Container).last);
      final decoration = container.decoration as BoxDecoration;
      // Verify the border color alpha channel matches a green tint.
      final borderColor = decoration.border!.top.color;
      final expectedColor = const Color(0xFF22C55E).withValues(alpha: 0.4);
      expect(borderColor.a, closeTo(expectedColor.a, 0.01));
    });

    testWidgets('shows Pending Verification label with amber color', (tester) async {
      await tester.pumpWidget(
        wrap(const DeviceStatusBadge(status: DeviceStatus.pendingVerification)),
      );
      expect(find.text('Pending Verification'), findsOneWidget);
    });

    testWidgets('shows Blocked label with red color', (tester) async {
      await tester.pumpWidget(wrap(const DeviceStatusBadge(status: DeviceStatus.blocked)));
      expect(find.text('Blocked'), findsOneWidget);
    });

    testWidgets('shows Revoked label with grey color', (tester) async {
      await tester.pumpWidget(wrap(const DeviceStatusBadge(status: DeviceStatus.revoked)));
      expect(find.text('Revoked'), findsOneWidget);
    });

    testWidgets('shows Verified label', (tester) async {
      await tester.pumpWidget(wrap(const DeviceStatusBadge(status: DeviceStatus.verified)));
      expect(find.text('Verified'), findsOneWidget);
    });
  });
}
