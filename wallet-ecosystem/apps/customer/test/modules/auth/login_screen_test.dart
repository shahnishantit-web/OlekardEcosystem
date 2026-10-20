import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/router/app_router.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';
import 'package:wallet_customer/modules/auth/domain/repositories/auth_repository.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';

class _LoginRepository extends Fake implements AuthRepository {
  final challenge = Completer<({String challengeToken, String method})>();
  String? phone;

  @override
  Future<({String challengeToken, String method})> initiateLogin({
    required String phone,
    required String method,
  }) {
    this.phone = phone;
    return challenge.future;
  }
}

class _NoBiometrics extends BiometricService {
  @override
  Future<bool> isAvailable() async => false;
}

void main() {
  testWidgets('login retains its route and opens PIN entry after the challenge',
      (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final repository = _LoginRepository();
    final container = ProviderContainer(overrides: [
      authRepositoryProvider.overrideWithValue(repository),
      biometricServiceProvider.overrideWithValue(_NoBiometrics()),
    ]);
    addTearDown(container.dispose);
    await tester.pumpWidget(UncontrolledProviderScope(
      container: container,
      child: Consumer(builder: (context, ref, _) {
        return MaterialApp.router(routerConfig: ref.watch(routerProvider));
      }),
    ));
    final router = container.read(routerProvider);
    router.go('/login');
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), '+26771000001');
    await tester.tap(find.text('Log In'));
    await tester.pump();
    expect(repository.phone, '+26771000001');
    expect(container.read(routerProvider), same(router));
    expect(router.routeInformationProvider.value.uri.path, '/login');

    repository.challenge.complete((challengeToken: 'challenge', method: 'pin'));
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(container.read(authProvider).status, AuthStateEnum.otpPending);
    expect(find.text('Enter your PIN'), findsOneWidget);
    expect(container.read(authProvider).isAuthenticated, isFalse);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
