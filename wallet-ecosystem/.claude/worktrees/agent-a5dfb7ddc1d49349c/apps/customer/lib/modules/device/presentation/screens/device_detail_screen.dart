import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/device.dart';
import '../device_list_providers.dart';
import '../widgets/device_status_badge.dart';
import 'block_device_confirmation_screen.dart';

class DeviceDetailScreen extends ConsumerStatefulWidget {
  final Device device;

  const DeviceDetailScreen({super.key, required this.device});

  @override
  ConsumerState<DeviceDetailScreen> createState() => _DeviceDetailScreenState();
}

class _DeviceDetailScreenState extends ConsumerState<DeviceDetailScreen> {
  bool _loading = false;

  Future<void> _trustDevice() async {
    setState(() => _loading = true);
    try {
      await ref.read(deviceRepositoryProvider).trustDevice(widget.device.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Device marked as trusted.')),
        );
        Navigator.pop(context);
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to trust device. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _removeDevice() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Remove device'),
        content: Text(
          'Remove "${widget.device.deviceName}"? All active sessions will be terminated.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: TextButton.styleFrom(foregroundColor: Colors.red),
            child: const Text('Remove'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    setState(() => _loading = true);
    try {
      await ref.read(deviceRepositoryProvider).removeDevice(widget.device.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Device removed.')),
        );
        Navigator.pop(context);
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to remove device. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _formatDateTime(DateTime? dt) {
    if (dt == null) return '—';
    return '${dt.day}/${dt.month}/${dt.year} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final device = widget.device;

    return Scaffold(
      appBar: AppBar(title: const Text('Device Details')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        device.platform == 'ios'
                            ? Icons.phone_iphone
                            : device.platform == 'android'
                                ? Icons.phone_android
                                : Icons.devices,
                        size: 40,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              device.deviceName,
                              style: Theme.of(context).textTheme.titleLarge,
                            ),
                            const SizedBox(height: 4),
                            DeviceStatusBadge(status: device.status),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 24),
                  _InfoRow(label: 'Platform', value: device.platform),
                  _InfoRow(label: 'Last seen', value: _formatDateTime(device.lastSeenAt)),
                  _InfoRow(label: 'Registered', value: _formatDateTime(device.createdAt)),
                  if (device.trustedAt != null)
                    _InfoRow(label: 'Trusted at', value: _formatDateTime(device.trustedAt)),
                  if (device.blockedAt != null) ...[
                    _InfoRow(label: 'Blocked at', value: _formatDateTime(device.blockedAt)),
                    if (device.blockedReason != null)
                      _InfoRow(label: 'Block reason', value: device.blockedReason!),
                  ],
                  const SizedBox(height: 32),
                  if (device.status == DeviceStatus.pendingVerification)
                    Semantics(
                      label: 'Mark ${device.deviceName} as trusted',
                      child: FilledButton.icon(
                        onPressed: _trustDevice,
                        icon: const Icon(Icons.verified),
                        label: const Text('Mark Trusted'),
                      ),
                    ),
                  const SizedBox(height: 12),
                  if (device.status != DeviceStatus.revoked) ...[
                    Semantics(
                      label: 'Block ${device.deviceName}',
                      child: OutlinedButton.icon(
                        onPressed: () => Navigator.push(
                          context,
                          MaterialPageRoute<void>(
                            builder: (_) => BlockDeviceConfirmationScreen(device: device),
                          ),
                        ),
                        icon: const Icon(Icons.block, color: Colors.red),
                        label: const Text(
                          'Block Device',
                          style: TextStyle(color: Colors.red),
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Semantics(
                      label: 'Remove ${device.deviceName}',
                      child: OutlinedButton.icon(
                        onPressed: _removeDevice,
                        icon: const Icon(Icons.delete_outline, color: Colors.red),
                        label: const Text(
                          'Remove Device',
                          style: TextStyle(color: Colors.red),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final String label;
  final String value;

  const _InfoRow({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: const TextStyle(color: Colors.grey, fontSize: 13),
            ),
          ),
          Expanded(
            child: Text(value, style: const TextStyle(fontSize: 13)),
          ),
        ],
      ),
    );
  }
}
