import 'package:flutter/material.dart';
import '../../domain/entities/device.dart';
import '../../../../core/theme/ok_theme_extension.dart';

class DeviceStatusBadge extends StatelessWidget {
  final DeviceStatus status;

  const DeviceStatusBadge({super.key, required this.status});

  @override
  Widget build(BuildContext context) {
    final ok = Theme.of(context).extension<OkThemeExtension>()!;

    final (bg, border, fg) = switch (status) {
      DeviceStatus.trusted             => (ok.successBg, ok.successBorder, ok.successFg),
      DeviceStatus.verified            => (ok.successBg, ok.successBorder, ok.successFg),
      DeviceStatus.pendingVerification => (ok.warningBg, ok.warningBorder, ok.warningFg),
      DeviceStatus.blocked             => (ok.errorBg,   ok.errorBorder,   ok.errorFg),
      DeviceStatus.revoked             => (
          const Color(0x33B8C4D0), // neutral bg — 20% of --color-neutral-secondary
          const Color(0xFF294052), // neutral border
          const Color(0xFFB8C4D0), // neutral fg
        ),
    };

    return Semantics(
      label: '${status.label} device',
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: border),
        ),
        child: Text(
          status.label,
          style: TextStyle(
            color: fg,
            fontSize: 11,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
