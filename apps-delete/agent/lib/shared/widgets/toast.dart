import 'package:flutter/material.dart';

enum ToastType { info, success, error, warning }

class AppToast {
  static void show(
    BuildContext context,
    String message, {
    ToastType type = ToastType.info,
  }) {
    final color = switch (type) {
      ToastType.success => Colors.green,
      ToastType.error   => Colors.red,
      ToastType.warning => Colors.orange,
      ToastType.info    => Colors.blueGrey,
    };

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Semantics(
          liveRegion: true,
          child: Text(message),
        ),
        backgroundColor: color,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }
}
