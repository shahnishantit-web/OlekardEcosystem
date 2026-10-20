import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agent/modules/auth/models/auth_models.dart';
import 'package:agent/modules/auth/services/auth_api_service.dart';
import 'package:agent/modules/auth/state/auth_state.dart';
import 'package:agent/modules/auth/state/auth_state_notifier.dart';
import 'package:agent/modules/auth/storage/auth_token_storage.dart';

// ── In-memory storage ────────────────────────────────────────────────────────

class _MemoryStorage implements AuthTokenStorage {
  String? _access, _refresh, _session;

  @override
  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
    String? sessionId,
  }) async {
    _access = accessToken;
    _refresh = refreshToken;
    _session = sessionId;
  }

  @override
  Future<String?> getAccessToken() async => _access;

  @override
  Future<String?> getRefreshToken() async => _refresh;

  @override
  Future<String?> getSessionId() async => _session;

  @override
  Future<void> clearTokens() async {
    _access = _refresh = _session = null;
  }

  @override
  Future<bool> hasTokens() async => _access != null && _access!.isNotEmpty;
}

// ── Stub API service ─────────────────────────────────────────────────────────

class _StubApi extends AuthApiService {
  _StubApi() : super.forTest();

  OtpChallengeData? registrationResult;
  OtpVerifyResult? verifyOtpResult;
  LoginResult? loginResult;
  AuthApiException? errorToThrow;

  @override
  Future<OtpChallengeData> initiateRegistration(String mobileNumber) async {
    if (errorToThrow != null) throw errorToThrow!;
    return registrationResult!;
  }

  @override
  Future<OtpVerifyResult> verifyOtp(String challengeId, String otp) async {
    if (errorToThrow != null) throw errorToThrow!;
    return verifyOtpResult!;
  }

  @override
  Future<OtpChallengeData> resendOtp(String challengeId) async {
    if (errorToThrow != null) throw errorToThrow!;
    return registrationResult!;
  }

  @override
  Future<void> setPin(String pin, {required String setupToken}) async {
    if (errorToThrow != null) throw errorToThrow!;
  }

  @override
  Future<void> changePin(String currentPin, String newPin) async {
    if (errorToThrow != null) throw errorToThrow!;
  }

  @override
  Future<LoginResult> login({
    required String identifier,
    required String credential,
    required String credentialType,
    required String deviceFingerprint,
  }) async {
    if (errorToThrow != null) throw errorToThrow!;
    return loginResult!;
  }

  @override
  Future<void> logout() async {}

  @override
  Future<String?> initiatePinUnblock(String mobileNumber) async {
    if (errorToThrow != null) throw errorToThrow!;
    return 'ch-unblock-001';
  }

  @override
  Future<void> completePinUnblock(String challengeId, String otp) async {
    if (errorToThrow != null) throw errorToThrow!;
  }
}

// ── Helper ───────────────────────────────────────────────────────────────────

ProviderContainer _container({_StubApi? api, _MemoryStorage? storage}) {
  final s = storage ?? _MemoryStorage();
  final a = api ?? _StubApi();
  return ProviderContainer(
    overrides: [
      authTokenStorageProvider.overrideWith((_) => s),
      authApiServiceProvider.overrideWith((_) => a),
    ],
  );
}

// ── Tests ────────────────────────────────────────────────────────────────────

void main() {
  group('AuthStateNotifier — Agent (T174)', () {
    test('starts unauthenticated when storage is empty', () async {
      final c = _container();
      addTearDown(c.dispose);
      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
    });

    test('restores authenticated state when tokens exist', () async {
      final storage = _MemoryStorage();
      await storage.saveTokens(
        accessToken: 'tok',
        refreshToken: 'ref',
        sessionId: 'ses-1',
      );
      final c = _container(storage: storage);
      addTearDown(c.dispose);
      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthAuthenticated>());
      expect((state as AuthAuthenticated).sessionId, 'ses-1');
    });

    test('initiateRegistration transitions to AuthOtpPending', () async {
      final api = _StubApi()
        ..registrationResult = OtpChallengeData(
          challengeId: 'ch-001',
          expiresAt: DateTime.now().add(const Duration(minutes: 10)),
          maskedDestination: '+267***4567',
        );
      final c = _container(api: api);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);

      await c
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthOtpPending>());
      expect((state as AuthOtpPending).challengeId, 'ch-001');
    });

    test('verifyOtp registration result transitions to AuthPinPending',
        () async {
      final api = _StubApi()
        ..registrationResult = OtpChallengeData(
          challengeId: 'ch-001',
          expiresAt: DateTime.now().add(const Duration(minutes: 10)),
        )
        ..verifyOtpResult = const OtpVerifyRegistration(
          userId: 'usr-1',
          setupToken: 'st-1',
        );
      final c = _container(api: api);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');
      await c.read(authStateNotifierProvider.future);
      await c
          .read(authStateNotifierProvider.notifier)
          .verifyOtp('ch-001', '123456');

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthPinPending>());
      expect((state as AuthPinPending).setupToken, 'st-1');
    });

    test('login success transitions to AuthAuthenticated and stores tokens',
        () async {
      final storage = _MemoryStorage();
      final api = _StubApi()
        ..loginResult = LoginSuccess(
          tokens: const SessionTokenData(
            accessToken: 'at',
            refreshToken: 'rt',
            sessionId: 'sid-1',
            expiresIn: 3600,
          ),
        );
      final c = _container(api: api, storage: storage);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: '',
          );

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthAuthenticated>());
      expect(await storage.getAccessToken(), 'at');
      expect(await storage.getSessionId(), 'sid-1');
    });

    test('login MFA required maps to AuthUnauthenticated with error', () async {
      final api = _StubApi()
        ..loginResult = const LoginMfaRequired(mfaToken: 'mfa-tok');
      final c = _container(api: api);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: '',
          );

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
      expect((state as AuthUnauthenticated).errorCode, 'IDENTITY_050');
    });

    test('login device verification required transitions to AuthDeviceVerificationRequired',
        () async {
      final api = _StubApi()
        ..loginResult =
            const LoginDeviceVerificationRequired(challengeId: 'dv-001');
      final c = _container(api: api);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c.read(authStateNotifierProvider.notifier).login(
            identifier: '+26771234567',
            credential: '123456',
            deviceFingerprint: '',
          );

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthDeviceVerificationRequired>());
    });

    test('initiatePinUnblock transitions to AuthPinUnblockPending', () async {
      final c = _container();
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c
          .read(authStateNotifierProvider.notifier)
          .initiatePinUnblock('+26771234567');

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthPinUnblockPending>());
      expect((state as AuthPinUnblockPending).challengeId, 'ch-unblock-001');
    });

    test('API error maps inline to state errorCode', () async {
      final api = _StubApi()
        ..errorToThrow = const AuthApiException(
          code: 'IDENTITY_001',
          message: 'Mobile number already registered.',
        );
      final c = _container(api: api);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c
          .read(authStateNotifierProvider.notifier)
          .initiateRegistration('+26771234567');

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
      expect((state as AuthUnauthenticated).errorCode, 'IDENTITY_001');
    });

    test('logout clears tokens and returns AuthUnauthenticated', () async {
      final storage = _MemoryStorage();
      await storage.saveTokens(
        accessToken: 'tok',
        refreshToken: 'ref',
        sessionId: 'ses',
      );
      final c = _container(storage: storage);
      addTearDown(c.dispose);
      await c.read(authStateNotifierProvider.future);
      await c.read(authStateNotifierProvider.notifier).logout();

      final state = await c.read(authStateNotifierProvider.future);
      expect(state, isA<AuthUnauthenticated>());
      expect(await storage.hasTokens(), isFalse);
    });
  });
}
