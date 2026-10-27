import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_payment/shared_payment.dart';

import 'package:wallet_agent/core/api/api_client.dart';
import 'package:wallet_agent/core/connectivity/connectivity_service.dart';
import 'package:wallet_agent/core/notifications/push_notification_service.dart';
import 'package:wallet_agent/core/router/app_router.dart';
import 'package:wallet_agent/core/security/app_lifecycle_observer.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';
import 'package:wallet_agent/modules/payment/agent_step_up_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  if (!kIsWeb) {
    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('Firebase init warning: $e');
    }
  }
  runApp(ProviderScope(
    overrides: [
      httpClientProvider.overrideWithValue(createDio(null)),
      stepUpServiceProvider.overrideWith((ref) => AgentStepUpService(ref)),
    ],
    child: const AgentApp(),
  ));
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
    if (!kIsWeb) {
      try {
        await ref.read(pushNotificationServiceProvider).initialize();
      } catch (e) {
        debugPrint('Push notification init warning: $e');
      }
    }

    // Seed online status
    try {
      final isOnline = await ref.read(connectivityServiceProvider).isOnline;
      ref.read(connectivityProvider.notifier).state = isOnline;

      // Subscribe to connectivity changes
      ref.read(connectivityServiceProvider).onConnectivityChanged.listen((online) {
        ref.read(connectivityProvider.notifier).state = online;
      });
    } catch (e) {
      debugPrint('Connectivity init warning: $e');
    }

    if (!kIsWeb) {
      try {
        await FirebaseMessaging.instance.getToken();
      } catch (e) {
        debugPrint('FCM token retrieval warning: $e');
      }
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
