export 'package:design_system/design_system.dart' show OkOfflineBanner;

import 'package:flutter/material.dart';
import 'package:design_system/design_system.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';

// OfflineBanner wraps OkOfflineBanner with agent app Riverpod connectivity state.
// connectivityProvider is true = online, so isOffline = !isOnline.
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isOnline = ref.watch(connectivityProvider);
    return OkOfflineBanner(isOffline: !isOnline);
  }
}
