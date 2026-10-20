import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/device.dart';
import '../device_list_providers.dart';
import '../widgets/device_list_tile.dart';
import 'device_detail_screen.dart';

class DeviceListScreen extends ConsumerStatefulWidget {
  const DeviceListScreen({super.key});

  @override
  ConsumerState<DeviceListScreen> createState() => _DeviceListScreenState();
}

class _DeviceListScreenState extends ConsumerState<DeviceListScreen> {
  int _page = 1;
  bool _actionInProgress = false;

  Future<void> _removeDevice(Device device) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Remove device'),
        content: Text(
          'Remove "${device.deviceName}"? All active sessions on this device will be terminated.',
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

    setState(() => _actionInProgress = true);
    try {
      await ref.read(deviceRepositoryProvider).removeDevice(device.id);
      ref.invalidate(deviceListProvider(_page));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('"${device.deviceName}" removed.')),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to remove device. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _actionInProgress = false);
    }
  }

  Future<void> _trustDevice(Device device) async {
    setState(() => _actionInProgress = true);
    try {
      await ref.read(deviceRepositoryProvider).trustDevice(device.id);
      ref.invalidate(deviceListProvider(_page));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('"${device.deviceName}" trusted.')),
        );
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to trust device. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _actionInProgress = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final deviceListAsync = ref.watch(deviceListProvider(_page));

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Devices'),
        actions: [
          if (_actionInProgress)
            const Padding(
              padding: EdgeInsets.all(16),
              child: SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
        ],
      ),
      body: deviceListAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (err, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('Failed to load devices.'),
              const SizedBox(height: 12),
              ElevatedButton(
                onPressed: () => ref.invalidate(deviceListProvider(_page)),
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
        data: (result) {
          if (result.devices.isEmpty) {
            return const Center(
              child: Text(
                'No registered devices.',
                style: TextStyle(color: Colors.grey),
              ),
            );
          }

          return Column(
            children: [
              Expanded(
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: result.devices.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (context, index) {
                    final device = result.devices[index];
                    return DeviceListTile(
                      device: device,
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute<void>(
                          builder: (_) => DeviceDetailScreen(device: device),
                        ),
                      ),
                      onTrust: device.status == DeviceStatus.pendingVerification
                          ? () => _trustDevice(device)
                          : null,
                      onRemove: device.status != DeviceStatus.revoked
                          ? () => _removeDevice(device)
                          : null,
                    );
                  },
                ),
              ),
              if (result.lastPage > 1)
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Semantics(
                        label: 'Previous page',
                        child: IconButton(
                          icon: const Icon(Icons.chevron_left),
                          onPressed: _page > 1
                              ? () => setState(() => _page = _page - 1)
                              : null,
                          tooltip: 'Previous page',
                        ),
                      ),
                      Text('Page $_page of ${result.lastPage}'),
                      Semantics(
                        label: 'Next page',
                        child: IconButton(
                          icon: const Icon(Icons.chevron_right),
                          onPressed: _page < result.lastPage
                              ? () => setState(() => _page = _page + 1)
                              : null,
                          tooltip: 'Next page',
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
