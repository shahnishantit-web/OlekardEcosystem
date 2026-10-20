import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/legacy.dart'
    show StateNotifier, StateNotifierProvider;
import '../domain/entities/customer.dart';
import '../domain/repositories/auth_repository.dart';
import '../data/repositories/auth_repository_impl.dart';
import '../../../core/di/providers.dart';
import '../../../core/security/secure_storage_service.dart';
import '../../../core/security/pin_service.dart';
import '../../../core/security/biometric_service.dart';
import '../../../core/notifications/push_notification_service.dart';

enum AuthStateEnum {
  unauthenticated,
  authenticating,
  authenticated,
  registering,
  otpPending,
  profileSetup,
  pinCreation,
}

class AuthState {
  final AuthStateEnum status;
  final Customer? customer;
  final String? error;
  final String? otpReference;
  final String? challengeToken;
  final String? registrationToken;

  const AuthState({
    required this.status,
    this.customer,
    this.error,
    this.otpReference,
    this.challengeToken,
    this.registrationToken,
  });

  bool get isAuthenticated => status == AuthStateEnum.authenticated;

  AuthState copyWith({
    AuthStateEnum? status,
    Customer? customer,
    String? error,
    String? otpReference,
    String? challengeToken,
    String? registrationToken,
  }) =>
      AuthState(
        status: status ?? this.status,
        customer: customer ?? this.customer,
        error: error,
        otpReference: otpReference ?? this.otpReference,
        challengeToken: challengeToken ?? this.challengeToken,
        registrationToken: registrationToken ?? this.registrationToken,
      );
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return AuthRepositoryImpl(
    apiClient: apiClient,
    storage: const SecureStorageService(),
  );
});

final pinServiceProvider = Provider<PinService>((ref) => const PinService());
final biometricServiceProvider =
    Provider<BiometricService>((ref) => BiometricService());

class AuthNotifier extends StateNotifier<AuthState> {
  final AuthRepository _repo;
  final PinService _pinService;
  final BiometricService _biometric;
  final Ref _ref;

  AuthNotifier(this._repo, this._pinService, this._biometric, this._ref)
      : super(const AuthState(status: AuthStateEnum.unauthenticated));

  Future<void> checkAuthState() async {
    const storage = SecureStorageService();
    final token = await storage.readAccessToken();
    if (token != null) {
      state = state.copyWith(status: AuthStateEnum.authenticated);
    } else {
      state = state.copyWith(status: AuthStateEnum.unauthenticated);
    }
  }

  Future<void> register(String phone) async {
    state = state.copyWith(status: AuthStateEnum.registering);
    try {
      final otpRef = await _repo.register(
        phone: phone,
        firstName: '',
        lastName: '',
      );
      state = state.copyWith(
        status: AuthStateEnum.otpPending,
        otpReference: otpRef,
      );
    } catch (e) {
      state = state.copyWith(
        status: AuthStateEnum.unauthenticated,
        error: e.toString(),
      );
    }
  }

  Future<void> initiateRegistration({
    required String phone,
    required String firstName,
    required String lastName,
  }) async {
    state = state.copyWith(status: AuthStateEnum.registering);
    try {
      final otpRef = await _repo.register(
        phone: phone,
        firstName: firstName,
        lastName: lastName,
      );
      state = state.copyWith(
        status: AuthStateEnum.otpPending,
        otpReference: otpRef,
      );
    } catch (e) {
      state = state.copyWith(
        status: AuthStateEnum.unauthenticated,
        error: e.toString(),
      );
    }
  }

  Future<void> verifyOtp(String code) async {
    final otpRef = state.otpReference;
    if (otpRef == null) return;

    try {
      final regToken = await _repo.verifyOtp(
        otpReference: otpRef,
        otpCode: code,
      );
      state = state.copyWith(
        status: AuthStateEnum.profileSetup,
        registrationToken: regToken,
      );
    } catch (e) {
      state = state.copyWith(error: e.toString());
    }
  }

  Future<void> completeRegistration(String firstName, String lastName) async {
    state = state.copyWith(status: AuthStateEnum.pinCreation);
    // firstName and lastName are captured via the registration token flow
  }

  Future<void> setPin(String pinHash) async {
    final regToken = state.registrationToken;
    if (regToken == null) return;

    try {
      final customer = await _repo.completeRegistration(
        registrationToken: regToken,
        pinHash: pinHash,
      );
      state = state.copyWith(
        status: AuthStateEnum.authenticated,
        customer: customer,
      );
      await _registerPushToken();
    } catch (e) {
      state = state.copyWith(
        status: AuthStateEnum.pinCreation,
        error: e.toString(),
      );
    }
  }

  Future<void> login(String phone) async {
    state = state.copyWith(status: AuthStateEnum.authenticating);
    try {
      // Try biometric first if available
      final biometricAvailable = await _biometric.isAvailable();
      if (biometricAvailable) {
        final authenticated = await _biometric.authenticate(
          'Authenticate to access your wallet',
        );
        if (authenticated) {
          state = state.copyWith(status: AuthStateEnum.authenticated);
          return;
        }
      }

      final result = await _repo.initiateLogin(phone: phone, method: 'pin');
      state = state.copyWith(
        status: AuthStateEnum.otpPending,
        challengeToken: result.challengeToken,
      );
    } catch (e) {
      state = state.copyWith(
        status: AuthStateEnum.unauthenticated,
        error: e.toString(),
      );
    }
  }

  Future<void> verifyLogin(String pin) async {
    final challengeToken = state.challengeToken;
    if (challengeToken == null) return;

    try {
      const storage = SecureStorageService();
      final customer = await _repo.verifyLogin(
        challengeToken: challengeToken,
        pin: pin,
      );
      // The server verifies the entered PIN. Cache a local verifier only after
      // successful authentication; a cached hash must never replace PIN input.
      final pinHash = await _pinService.hashPin(pin);
      await storage.writePinHash(pinHash);
      state = state.copyWith(
        status: AuthStateEnum.authenticated,
        customer: customer,
      );
      await _registerPushToken();
    } catch (e) {
      state = state.copyWith(error: e.toString());
    }
  }

  Future<void> logout() async {
    await _repo.logout();
    state = const AuthState(status: AuthStateEnum.unauthenticated);
    _ref.invalidateSelf();
  }

  void requireReauth() {
    state = const AuthState(status: AuthStateEnum.unauthenticated);
  }

  Future<void> _registerPushToken() async {
    try {
      final fcmToken = await PushNotificationService.getToken();
      final deviceId = await const SecureStorageService().readDeviceId();
      if (fcmToken != null && deviceId != null) {
        await _repo.registerPushToken(deviceId: deviceId, fcmToken: fcmToken);
      }
    } catch (_) {}
  }
}

final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final repo = ref.watch(authRepositoryProvider);
  final pinService = ref.watch(pinServiceProvider);
  final biometric = ref.watch(biometricServiceProvider);
  return AuthNotifier(repo, pinService, biometric, ref);
});
