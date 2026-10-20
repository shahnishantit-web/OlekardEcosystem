import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'core/notifications/deep_link_handler.dart';
import 'core/notifications/push_notification_service.dart';
import 'core/router/app_router.dart';
import 'core/security/app_lifecycle_observer.dart';
import 'core/theme/app_theme.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();
  await PushNotificationService.initialize();
  runApp(const ProviderScope(child: CustomerApp()));
}

class CustomerApp extends ConsumerStatefulWidget {
  const CustomerApp({super.key});

  @override
  ConsumerState<CustomerApp> createState() => _CustomerAppState();
}

class _CustomerAppState extends ConsumerState<CustomerApp> {
  late AppLifecycleObserver _lifecycleObserver;

  @override
  void initState() {
    super.initState();
    _lifecycleObserver = AppLifecycleObserver(ref);
    WidgetsBinding.instance.addObserver(_lifecycleObserver);
    _handleInitialNotification();
    _listenForegroundMessages();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(_lifecycleObserver);
    super.dispose();
  }

  Future<void> _handleInitialNotification() async {
    // Handles terminated-state notification deep links
    final message = await FirebaseMessaging.instance.getInitialMessage();
    if (message != null) {
      final route = DeepLinkHandler.routeForMessage(message);
      if (route != null && mounted) {
        final router = ref.read(routerProvider);
        router.go(route);
      }
    }
  }

  void _listenForegroundMessages() {
    PushNotificationService.foregroundMessages.listen((message) {
      final route = DeepLinkHandler.routeForMessage(message);
      if (route != null && mounted) {
        final router = ref.read(routerProvider);
        router.go(route);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    return MaterialApp.router(
      title: 'OleKard Wallet',
      theme: AppTheme.light,
      routerConfig: router,
      debugShowCheckedModeBanner: false,
    );
  }
}
