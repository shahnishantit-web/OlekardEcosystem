import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/theme/app_theme.dart';

void main() {
  group('LoginScreen golden — Consumer Green theme tokens', () {
    Widget buildSubject() {
      return ProviderScope(
        child: MaterialApp(
          theme: AppTheme.dark,
          home: const _LoginShell(),
        ),
      );
    }

    testWidgets('login form renders with Consumer Green theme', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(buildSubject());
      await tester.pump();

      await expectLater(
        find.byType(MaterialApp),
        matchesGoldenFile('goldens/login_screen.png'),
      );
    });

    testWidgets('scaffold background is Consumer Green (#001B12)', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pump();

      // backgroundColor is null when set via theme.scaffoldBackgroundColor
      final theme = Theme.of(tester.element(find.byType(Scaffold).first));
      expect(theme.scaffoldBackgroundColor, const Color(0xFF001B12));
    });
  });
}

/// Minimal login form shell — avoids go_router and network providers.
class _LoginShell extends StatelessWidget {
  const _LoginShell();

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
              Text('OleKard', style: theme.textTheme.headlineMedium),
              const SizedBox(height: 8),
              Text('Sign in to your account', style: theme.textTheme.bodyMedium),
              const SizedBox(height: 32),
              TextFormField(
                decoration: const InputDecoration(
                  labelText: 'Phone number',
                  hintText: '+267 71 000 000',
                ),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: () {},
                child: const Text('Continue'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
