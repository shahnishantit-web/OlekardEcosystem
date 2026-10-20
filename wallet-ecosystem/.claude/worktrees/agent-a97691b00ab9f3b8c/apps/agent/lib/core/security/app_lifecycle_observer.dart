import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/modules/auth/presentation/auth_providers.dart';

const Duration _backgroundTimeout = Duration(minutes: 5);

class AppLifecycleObserver extends WidgetsBindingObserver {
  final WidgetRef _ref;
  DateTime? _backgroundedAt;

  AppLifecycleObserver(this._ref);

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.hidden) {
      _backgroundedAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed) {
      final backgroundedAt = _backgroundedAt;
      if (backgroundedAt != null) {
        final elapsed = DateTime.now().difference(backgroundedAt);
        if (elapsed >= _backgroundTimeout) {
          _ref.read(authNotifierProvider.notifier).requireReauth();
        }
      }
      _backgroundedAt = null;
    }
  }
}
