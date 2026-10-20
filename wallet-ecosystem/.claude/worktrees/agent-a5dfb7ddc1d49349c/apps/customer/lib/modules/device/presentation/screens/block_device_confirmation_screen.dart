import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../domain/entities/device.dart';
import '../device_list_providers.dart';

class BlockDeviceConfirmationScreen extends ConsumerStatefulWidget {
  final Device device;

  const BlockDeviceConfirmationScreen({super.key, required this.device});

  @override
  ConsumerState<BlockDeviceConfirmationScreen> createState() =>
      _BlockDeviceConfirmationScreenState();
}

class _BlockDeviceConfirmationScreenState
    extends ConsumerState<BlockDeviceConfirmationScreen> {
  final _reasonController = TextEditingController();
  bool _loading = false;

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _confirmBlock() async {
    setState(() => _loading = true);
    try {
      final reason = _reasonController.text.trim();
      await ref.read(deviceRepositoryProvider).blockDevice(
            widget.device.id,
          );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              '"${widget.device.deviceName}" has been blocked${reason.isNotEmpty ? ': $reason' : '.'} ',
            ),
          ),
        );
        // Pop twice — back to device list
        Navigator.pop(context);
        Navigator.pop(context);
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to block device. Please try again.')),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Block Device')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.block, size: 48, color: Colors.red),
                  const SizedBox(height: 16),
                  Text(
                    'Block "${widget.device.deviceName}"?',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'This will terminate all active sessions on this device and prevent future logins from it.',
                    style: TextStyle(fontSize: 14),
                  ),
                  const SizedBox(height: 24),
                  Semantics(
                    label: 'Reason for blocking (optional)',
                    child: TextField(
                      controller: _reasonController,
                      decoration: const InputDecoration(
                        labelText: 'Reason (optional)',
                        hintText: 'e.g. Lost device, unauthorized access',
                        border: OutlineInputBorder(),
                      ),
                      maxLines: 2,
                      maxLength: 255,
                    ),
                  ),
                  const Spacer(),
                  Semantics(
                    label: 'Confirm block device',
                    child: FilledButton.icon(
                      onPressed: _confirmBlock,
                      style: FilledButton.styleFrom(
                        backgroundColor: Colors.red,
                      ),
                      icon: const Icon(Icons.block),
                      label: const Text('Confirm Block'),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Semantics(
                    label: 'Cancel blocking device',
                    child: OutlinedButton(
                      onPressed: () => Navigator.pop(context),
                      child: const Text('Cancel'),
                    ),
                  ),
                ],
              ),
            ),
    );
  }
}
