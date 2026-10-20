import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/notifications/push_notification_service.dart';
import 'package:wallet_agent/core/router/app_router.dart';
import 'package:wallet_agent/core/security/app_lifecycle_observer.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Firebase.initializeApp();
  runApp(const ProviderScope(child: AgentApp()));
}

class AgentApp extends ConsumerStatefulWidget {
  const AgentApp({super.key});

  @override
  ConsumerState<AgentApp> createState() => _AgentAppState();
}

class _AgentAppState extends ConsumerState<AgentApp> {
  late final AppLifecycleObserver _lifecycleObserver;

  @override
  void initState() {
    super.initState();
    _lifecycleObserver = AppLifecycleObserver(ref);
    WidgetsBinding.instance.addObserver(_lifecycleObserver);
    _initServices();
  }

  Future<void> _initServices() async {
    // Initialize push notifications
    await ref.read(pushNotificationServiceProvider).initialize();

    // Seed online status
    final isOnline = await ref.read(connectivityServiceProvider).isOnline;
    ref.read(connectivityProvider.notifier).state = isOnline;

    // Subscribe to connectivity changes
    ref.read(connectivityServiceProvider).onConnectivityChanged.listen((online) {
      ref.read(connectivityProvider.notifier).state = online;
    });

    // Request FCM permission and register device (handled inside PushNotificationService)
    final fcmToken = await FirebaseMessaging.instance.getToken();
    if (fcmToken != null) {
      // Device registration is triggered from AuthNotifier after login.
      // FCM token is stored and sent post-registration in auth flow.
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(_lifecycleObserver);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);

    return MaterialApp.router(
      title: 'Agent Portal',
      theme: AppTheme.light,
      darkTheme: AppTheme.dark,
      themeMode: ThemeMode.system,
      routerConfig: router,
      debugShowCheckedModeBanner: false,
    );
  }
}
