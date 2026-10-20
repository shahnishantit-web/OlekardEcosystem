import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../modules/auth/presentation/auth_providers.dart';

class AppLifecycleObserver with WidgetsBindingObserver {
  static const _backgroundTimeoutMinutes = 5;

  final WidgetRef _ref;
  DateTime? _backgroundedAt;

  AppLifecycleObserver(this._ref);

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused:
        _backgroundedAt = DateTime.now();
      case AppLifecycleState.resumed:
        final backgrounded = _backgroundedAt;
        if (backgrounded != null) {
          final elapsed = DateTime.now().difference(backgrounded);
          if (elapsed.inMinutes >= _backgroundTimeoutMinutes) {
            _ref.read(authProvider.notifier).requireReauth();
          }
        }
        _backgroundedAt = null;
      default:
        break;
    }
  }
}
