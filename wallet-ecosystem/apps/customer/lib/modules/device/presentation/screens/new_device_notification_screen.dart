import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/device.dart';
import '../device_list_providers.dart';
import '../widgets/device_status_badge.dart';

class NewDeviceNotificationScreen extends ConsumerStatefulWidget {
  final Device device;

  const NewDeviceNotificationScreen({super.key, required this.device});

  @override
  ConsumerState<NewDeviceNotificationScreen> createState() =>
      _NewDeviceNotificationScreenState();
}

class _NewDeviceNotificationScreenState
    extends ConsumerState<NewDeviceNotificationScreen> {
  bool _loading = false;

  Future<void> _acknowledge({required bool approved}) async {
    setState(() => _loading = true);
    try {
      await ref
          .read(deviceRepositoryProvider)
          .acknowledgeNewDevice(widget.device.id, acknowledged: approved);
      if (mounted) {
        final message = approved
            ? 'New device approved.'
            : 'New device login denied. The device has been blocked.';
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(message)));
        Navigator.pop(context);
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Action failed. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final device = widget.device;

    return Scaffold(
      appBar: AppBar(title: const Text('New Device Login')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.devices_other, size: 48, color: Colors.orange),
                  const SizedBox(height: 16),
                  Text(
                    'New device detected',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'A login attempt was made from a new device. Was this you?',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 24),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
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
                                size: 24,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  device.deviceName,
                                  style: const TextStyle(fontWeight: FontWeight.w600),
                                ),
                              ),
                              DeviceStatusBadge(status: device.status),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'Platform: ${device.platform}',
                            style: const TextStyle(fontSize: 13, color: Colors.grey),
                          ),
                          Text(
                            'Registered: ${device.createdAt.day}/${device.createdAt.month}/${device.createdAt.year}',
                            style: const TextStyle(fontSize: 13, color: Colors.grey),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const Spacer(),
                  Semantics(
                    label: 'Approve this device login',
                    child: FilledButton.icon(
                      onPressed: () => _acknowledge(approved: true),
                      icon: const Icon(Icons.check_circle_outline),
                      label: const Text('Yes, this was me'),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Semantics(
                    label: 'Deny this device and block it',
                    child: OutlinedButton.icon(
                      onPressed: () => _acknowledge(approved: false),
                      icon: const Icon(Icons.block, color: Colors.red),
                      label: const Text(
                        "No, block this device",
                        style: TextStyle(color: Colors.red),
                      ),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
