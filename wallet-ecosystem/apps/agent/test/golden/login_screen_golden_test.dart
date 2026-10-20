import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_agent/core/theme/app_theme.dart';

void main() {
  group('Agent LoginScreen golden — Agent Blue theme tokens', () {
    Widget buildSubject() {
      return const ProviderScope(
        child: MaterialApp(
          theme: null, // set below
          home: _AgentLoginShell(),
        ),
      );
    }

    Widget buildWithTheme() {
      return MaterialApp(
        theme: AppTheme.dark,
        home: const _AgentLoginShell(),
      );
    }

    testWidgets('login form renders with Agent Blue theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildWithTheme());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/agent_login_screen.png'),
      );
    });

    testWidgets('scaffold background is Agent Blue (#001329)', (tester) async {
      await tester.pumpWidget(buildWithTheme());
      await tester.pump();

      // backgroundColor is null when set via theme.scaffoldBackgroundColor
      final theme = Theme.of(tester.element(find.byType(Scaffold).first));
      expect(theme.scaffoldBackgroundColor, const Color(0xFF001329));
    });

    testWidgets('primary color is Agent Blue (#2563EB)', (tester) async {
      late Color? primary;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark,
          home: Builder(
            builder: (context) {
              primary = Theme.of(context).colorScheme.primary;
              return const SizedBox();
            },
          ),
        ),
      );
      await tester.pump();
      expect(primary, const Color(0xFF2563EB));
    });
  });
}

class _AgentLoginShell extends StatelessWidget {
  const _AgentLoginShell();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 48),
              Text('OleKard Agent', style: theme.textTheme.headlineLarge),
              const SizedBox(height: 8),
              Text('Agent portal sign in', style: theme.textTheme.bodyMedium),
              const SizedBox(height: 32),
              TextFormField(
                decoration: const InputDecoration(
                  labelText: 'Phone number',
                  hintText: '+267 72 000 000',
                ),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: () {},
                child: const Text('Sign In'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
