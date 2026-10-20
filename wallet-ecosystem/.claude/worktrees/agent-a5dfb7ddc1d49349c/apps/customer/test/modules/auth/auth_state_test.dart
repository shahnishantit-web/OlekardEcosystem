import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';

void main() {
  group('AuthState', () {
    test('initial state is unauthenticated', () {
      const state = AuthState(status: AuthStateEnum.unauthenticated);
      expect(state.status, AuthStateEnum.unauthenticated);
      expect(state.customer, isNull);
      expect(state.error, isNull);
    });

    test('isAuthenticated returns true only for authenticated status', () {
      const auth = AuthState(status: AuthStateEnum.authenticated);
      const unauth = AuthState(status: AuthStateEnum.unauthenticated);
      expect(auth.isAuthenticated, isTrue);
      expect(unauth.isAuthenticated, isFalse);
    });

    test('copyWith overrides only specified fields', () {
      const original = AuthState(status: AuthStateEnum.unauthenticated);
      final updated = original.copyWith(
        status: AuthStateEnum.otpPending,
        error: 'invalid otp',
      );
      expect(updated.status, AuthStateEnum.otpPending);
      expect(updated.error, 'invalid otp');
      expect(original.status, AuthStateEnum.unauthenticated);
    });

    test('copyWith preserves otpReference when not overridden', () {
      const original = AuthState(
        status: AuthStateEnum.otpPending,
        otpReference: 'otp-ref-123',
      );
      final updated = original.copyWith(error: 'bad otp');
      expect(updated.otpReference, 'otp-ref-123');
    });
  });
}
