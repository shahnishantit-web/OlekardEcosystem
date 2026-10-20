import 'package:flutter/material.dart';
import 'package:design_system/design_system.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/connectivity/connectivity_service.dart';

export 'package:design_system/design_system.dart' show OkOfflineBanner;

// OfflineBanner wraps OkOfflineBanner with app-local Riverpod connectivity state.
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final offline = ref.watch(isOfflineProvider);
    return OkOfflineBanner(isOffline: offline);
  }
}
