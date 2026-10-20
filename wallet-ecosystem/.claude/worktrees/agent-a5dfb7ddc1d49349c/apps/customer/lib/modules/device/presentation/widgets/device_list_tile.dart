import 'package:flutter/material.dart';
import '../../domain/entities/device.dart';
import 'device_status_badge.dart';

class DeviceListTile extends StatelessWidget {
  final Device device;
  final VoidCallback? onTap;
  final VoidCallback? onTrust;
  final VoidCallback? onRemove;

  const DeviceListTile({
    super.key,
    required this.device,
    this.onTap,
    this.onTrust,
    this.onRemove,
  });

  IconData _platformIcon() => switch (device.platform) {
    'ios'     => Icons.phone_iphone,
    'android' => Icons.phone_android,
    _         => Icons.devices,
  };

  String _relativeTime(DateTime? dt) {
    if (dt == null) return 'Never';
    final diff = DateTime.now().difference(dt);
    if (diff.inDays > 0) return '${diff.inDays}d ago';
    if (diff.inHours > 0) return '${diff.inHours}h ago';
    if (diff.inMinutes > 0) return '${diff.inMinutes}m ago';
    return 'Just now';
  }

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap,
      leading: Semantics(
        label: device.platform,
        child: Icon(_platformIcon(), size: 28),
      ),
      title: Text(
        device.deviceName,
        style: const TextStyle(fontWeight: FontWeight.w500),
      ),
      subtitle: Row(
        children: [
          DeviceStatusBadge(status: device.status),
          const SizedBox(width: 8),
          Text(
            _relativeTime(device.lastSeenAt),
            style: const TextStyle(fontSize: 11, color: Colors.grey),
          ),
        ],
      ),
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (onTrust != null)
            Semantics(
              label: 'Trust ${device.deviceName}',
              child: TextButton(
                onPressed: onTrust,
                child: const Text('Trust'),
              ),
            ),
          if (onRemove != null)
            Semantics(
              label: 'Remove ${device.deviceName}',
              child: TextButton(
                onPressed: onRemove,
                style: TextButton.styleFrom(foregroundColor: Colors.red),
                child: const Text('Remove'),
              ),
            ),
        ],
      ),
    );
  }
}
