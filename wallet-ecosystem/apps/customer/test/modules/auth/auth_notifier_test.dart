import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/security/biometric_service.dart';
import 'package:wallet_customer/core/security/pin_service.dart';
import 'package:wallet_customer/core/security/secure_storage_service.dart';
import 'package:wallet_customer/modules/auth/domain/entities/customer.dart';
import 'package:wallet_customer/modules/auth/domain/repositories/auth_repository.dart';
import 'package:wallet_customer/modules/auth/presentation/auth_providers.dart';

class _Repository extends Fake implements AuthRepository {
  final customer = Customer(
    id: 'customer-test',
    phone: '+26771000001',
    firstName: 'Test',
    lastName: 'Customer',
    kycStatus: KycStatus.none,
    locale: 'en',
    createdAt: DateTime(2026),
  );
  String? lastOtp;
  String? lastChallenge;
  String? lastPinHash;
  String? lastLoginPin;
  String? lastRegistrationToken;
  var loginCalls = 0;
  var loggedOut = false;
  var rejectOtp = false;
  var rejectPin = false;

  @override
  Future<String> register(
          {required String phone,
          required String firstName,
          required String lastName}) async =>
      'otp-ref';

  @override
  Future<String> verifyOtp(
      {required String otpReference, required String otpCode}) async {
    lastOtp = otpCode;
    if (rejectOtp) throw StateError('OTP rejected');
    return 'registration-token';
  }

  @override
  Future<Customer> completeRegistration(
      {required String registrationToken,
      required String pinHash,
      String? email}) async {
    lastRegistrationToken = registrationToken;
    lastPinHash = pinHash;
    return customer;
  }

  @override
  Future<({String challengeToken, String method})> initiateLogin(
      {required String phone, required String method}) async {
    loginCalls++;
    expect(method, 'pin');
    return (challengeToken: 'challenge-token', method: method);
  }

  @override
  Future<Customer> verifyLogin(
      {required String challengeToken, required String pin}) async {
    lastChallenge = challengeToken;
    lastLoginPin = pin;
    if (rejectPin) throw StateError('PIN rejected');
    return customer;
  }

  @override
  Future<void> logout() async {
    loggedOut = true;
  }
}

class _Biometric extends BiometricService {
  var available = false;
  var accepted = false;
  @override
  Future<bool> isAvailable() async => available;
  @override
  Future<bool> authenticate(String localizedReason) async => accepted;
}

class _Pin extends PinService {
  @override
  Future<String> hashPin(String pin) async => 'test-pin-hash';
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late _Repository repository;
  late _Biometric biometric;
  late ProviderContainer container;
  late List<AuthState> states;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    repository = _Repository();
    biometric = _Biometric();
    container = ProviderContainer(overrides: [
      authRepositoryProvider.overrideWithValue(repository),
      biometricServiceProvider.overrideWithValue(biometric),
      pinServiceProvider.overrideWithValue(_Pin()),
    ]);
    states = [];
    // The same typed provider/listener inference used by the auth screens.
    container.listen<AuthState>(authProvider, (_, next) => states.add(next),
        fireImmediately: true);
  });
  tearDown(() => container.dispose());

  test(
      'registration preserves OTP and registration tokens through PIN creation',
      () async {
    final notifier = container.read(authProvider.notifier);
    await notifier.initiateRegistration(
        phone: '+26771000001', firstName: 'Test', lastName: 'Customer');
    await notifier.verifyOtp('123456');
    await notifier.completeRegistration('Test', 'Customer');
    await notifier.setPin('registration-pin-hash');
    expect(states.map((s) => s.status), [
      AuthStateEnum.unauthenticated,
      AuthStateEnum.registering,
      AuthStateEnum.otpPending,
      AuthStateEnum.profileSetup,
      AuthStateEnum.pinCreation,
      AuthStateEnum.authenticated,
    ]);
    expect(repository.lastOtp, '123456');
    expect(repository.lastRegistrationToken, 'registration-token');
    expect(repository.lastPinHash, 'registration-pin-hash');
    expect(container.read(authProvider).customer, repository.customer);
  });

  test('rejected OTP retains its reference and does not advance registration',
      () async {
    final notifier = container.read(authProvider.notifier);
    await notifier.register('+26771000001');
    repository.rejectOtp = true;
    await notifier.verifyOtp('000000');
    final state = container.read(authProvider);
    expect(state.status, AuthStateEnum.otpPending);
    expect(state.otpReference, 'otp-ref');
    expect(state.registrationToken, isNull);
    expect(state.error, contains('OTP rejected'));
  });

  test('failed biometrics fall back to the backend PIN challenge', () async {
    biometric.available = true;
    await container.read(authProvider.notifier).login('+26771000001');
    expect(repository.loginCalls, 1);
    expect(container.read(authProvider).status, AuthStateEnum.otpPending);
    expect(container.read(authProvider).challengeToken, 'challenge-token');
  });

  test('PIN login submits entered PIN and caches its verifier after success',
      () async {
    final notifier = container.read(authProvider.notifier);
    await notifier.login('+26771000001');
    await notifier.verifyLogin('123456');
    expect(repository.lastChallenge, 'challenge-token');
    expect(repository.lastLoginPin, '123456');
    expect(await const SecureStorageService().readPinHash(), 'test-pin-hash');
    expect(container.read(authProvider).isAuthenticated, isTrue);
  });

  test('a cached hash never replaces entered PIN or authenticates a rejection',
      () async {
    FlutterSecureStorage.setMockInitialValues({'pin_hash': 'cached-pin-hash'});
    repository.rejectPin = true;
    final notifier = container.read(authProvider.notifier);
    await notifier.login('+26771000001');
    await notifier.verifyLogin('654321');
    expect(repository.lastLoginPin, '654321');
    expect(await const SecureStorageService().readPinHash(), 'cached-pin-hash');
    expect(container.read(authProvider).isAuthenticated, isFalse);
    expect(container.read(authProvider).error, contains('PIN rejected'));
  });

  test(
      'existing session restoration, reauthentication and logout still publish states',
      () async {
    FlutterSecureStorage.setMockInitialValues(
        {'access_token': 'test-session-token'});
    final notifier = container.read(authProvider.notifier);
    await notifier.checkAuthState();
    expect(container.read(authProvider).isAuthenticated, isTrue);
    notifier.requireReauth();
    expect(container.read(authProvider).isAuthenticated, isFalse);
    await notifier.logout();
    expect(repository.loggedOut, isTrue);
    expect(container.read(authProvider).status, AuthStateEnum.unauthenticated);
  });
}
