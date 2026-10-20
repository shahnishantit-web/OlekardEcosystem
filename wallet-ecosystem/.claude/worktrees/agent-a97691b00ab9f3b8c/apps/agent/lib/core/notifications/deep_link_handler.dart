import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';

abstract final class DeepLinkHandler {
  static String? routeForNotificationType(String? type) {
    return switch (type) {
      'float_approved' => '/float',
      'float_rejected' => '/float',
      'commission_credited' => '/commissions',
      'security_alert' => '/security',
      _ => null,
    };
  }

  static void handleRemoteMessage(RemoteMessage message, BuildContext context) {
    final type = message.data['type'] as String?;
    final route = routeForNotificationType(type);
    if (route != null) {
      context.push(route);
    }
  }
}
