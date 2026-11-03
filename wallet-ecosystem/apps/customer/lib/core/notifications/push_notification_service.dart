import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

@pragma('vm:entry-point')
Future<void> _firebaseBackgroundHandler(RemoteMessage message) async {
  // Background message handling — no UI interaction possible here
}

class PushNotificationService {
  static final _localNotifications = FlutterLocalNotificationsPlugin();
  static StreamSubscription<String>? _registrationSubscription;
  static String? _registrationToken;
  static const _androidChannel = AndroidNotificationChannel(
    'wallet_notifications',
    'Wallet Notifications',
    description: 'Transaction alerts and account updates',
    importance: Importance.high,
  );

  static Future<void> initialize() async {
    FirebaseMessaging.onBackgroundMessage(_firebaseBackgroundHandler);

    await _localNotifications.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(
          requestAlertPermission: true,
          requestBadgePermission: true,
          requestSoundPermission: true,
        ),
      ),
    );

    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(_androidChannel);

    await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    _registrationSubscription ??=
        FirebaseMessaging.instance.onRegistered.listen(_onRegistered);
    await FirebaseMessaging.instance.register();
  }

  static void _onRegistered(String token) {
    _registrationToken = token;
  }

  static Future<String?> getToken() async {
    final currentToken = _registrationToken;
    if (currentToken != null) return currentToken;

    final registration = FirebaseMessaging.instance.onRegistered.first
        .then<String?>((token) => token)
        .timeout(
          const Duration(seconds: 10),
          onTimeout: () => null,
        );
    await FirebaseMessaging.instance.register();
    return registration;
  }

  static Stream<RemoteMessage> get foregroundMessages =>
      FirebaseMessaging.onMessage;
}
