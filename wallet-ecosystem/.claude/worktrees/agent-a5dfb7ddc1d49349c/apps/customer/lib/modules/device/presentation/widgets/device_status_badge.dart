import 'package:flutter/material.dart';
import '../../domain/entities/device.dart';

class DeviceStatusBadge extends StatelessWidget {
  final DeviceStatus status;

  const DeviceStatusBadge({super.key, required this.status});

  // Background tint color (12% opacity of the semantic color)
  Color _bgColor() => switch (status) {
    DeviceStatus.trusted             => const Color(0xFF22C55E),
    DeviceStatus.verified            => const Color(0xFF22C55E),
    DeviceStatus.pendingVerification => const Color(0xFFF59E0B),
    DeviceStatus.blocked             => const Color(0xFFEF4444),
    DeviceStatus.revoked             => const Color(0xFF9CA3AF),
  };

  // Darker text colors for WCAG AA contrast (≥4.5:1) on the light tint background
  Color _textColor() => switch (status) {
    DeviceStatus.trusted             => const Color(0xFF15803D),
    DeviceStatus.verified            => const Color(0xFF15803D),
    DeviceStatus.pendingVerification => const Color(0xFF92400E),
    DeviceStatus.blocked             => const Color(0xFFB91C1C),
    DeviceStatus.revoked             => const Color(0xFF4B5563),
  };

  @override
  Widget build(BuildContext context) {
    final bg   = _bgColor();
    final text = _textColor();
    return Semantics(
      label: '${status.label} device',
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: bg.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: bg.withValues(alpha: 0.4)),
        ),
        child: Text(
          status.label,
          style: TextStyle(
            color: text,
            fontSize: 11,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
