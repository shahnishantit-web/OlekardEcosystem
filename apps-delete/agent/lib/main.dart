import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/router.dart';
import 'core/config/app_environment.dart';
import 'core/di/providers.dart';
import 'core/theme/app_theme.dart';

void main() {
  final env = AppEnvironmentX.fromDartDefine();
  runApp(
    ProviderScope(
      overrides: [
        environmentProvider.overrideWithValue(env),
        ...rootProviderOverrides,
      ],
      child: const AgentApp(),
    ),
  );
}

final environmentProvider = Provider<AppEnvironment>(
  (_) => AppEnvironmentX.fromDartDefine(),
);

class AgentApp extends ConsumerWidget {
  const AgentApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    return MaterialApp.router(
      title: 'OleKard Agent',
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      routerConfig: router,
      debugShowCheckedModeBanner: false,
    );
  }
}
