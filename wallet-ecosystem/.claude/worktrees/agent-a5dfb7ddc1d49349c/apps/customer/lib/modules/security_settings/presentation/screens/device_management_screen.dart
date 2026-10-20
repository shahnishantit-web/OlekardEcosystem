import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/di/providers.dart';
import '../../../../core/theme/design_tokens.dart';
import '../../../../shared/widgets/error_view.dart';
import '../../../../shared/widgets/loading_shimmer.dart';

class TrustedDevice {
  final String id;
  final String name;
  final String platform;
  final DateTime lastSeenAt;
  final bool isCurrent;

  const TrustedDevice({
    required this.id,
    required this.name,
    required this.platform,
    required this.lastSeenAt,
    required this.isCurrent,
  });
}

final _devicesProvider = FutureProvider.autoDispose<List<TrustedDevice>>((ref) async {
  final dio = ref.watch(apiClientProvider).dio;
  final res = await dio.get('/v1/customer/devices');
  return (res.data as List).map((e) {
    final j = e as Map<String, dynamic>;
    return TrustedDevice(
      id: j['id'] as String,
      name: j['name'] as String,
      platform: j['platform'] as String? ?? 'unknown',
      lastSeenAt: DateTime.parse(j['last_seen_at'] as String),
      isCurrent: j['is_current'] as bool? ?? false,
    );
  }).toList();
});

class DeviceManagementScreen extends ConsumerWidget {
  const DeviceManagementScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final devicesAsync = ref.watch(_devicesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Trusted Devices')),
      body: devicesAsync.when(
        loading: () => const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: ShimmerList(itemCount: 4),
        ),
        error: (e, _) => ErrorView(message: e.toString()),
        data: (devices) => ListView.separated(
          itemCount: devices.length,
          separatorBuilder: (_, __) => const Divider(height: 1),
          itemBuilder: (context, i) {
            final d = devices[i];
            return ListTile(
              leading: Icon(
                d.platform == 'ios' ? Icons.phone_iphone : Icons.phone_android,
              ),
              title: Row(children: [
                Text(d.name, style: AppTypography.label),
                if (d.isCurrent) ...[
                  const SizedBox(width: 8),
                  const Chip(label: Text('This device'), padding: EdgeInsets.zero),
                ],
              ]),
              subtitle: Text(
                'Last seen ${d.lastSeenAt.toLocal().toString().substring(0, 16)}',
                style: AppTypography.caption,
              ),
              trailing: d.isCurrent
                  ? null
                  : IconButton(
                      icon: const Icon(Icons.delete_outline, color: AppColors.error),
                      tooltip: 'Remove device',
                      onPressed: () => _removeDevice(context, ref, d.id),
                    ),
            );
          },
        ),
      ),
    );
  }

  Future<void> _removeDevice(BuildContext context, WidgetRef ref, String deviceId) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Remove device?'),
        content: const Text('This device will need to re-authenticate to access your account.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Remove')),
        ],
      ),
    );
    if (confirmed == true) {
      final dio = ref.read(apiClientProvider).dio;
      await dio.delete('/v1/customer/devices/$deviceId');
      ref.invalidate(_devicesProvider);
    }
  }
}
