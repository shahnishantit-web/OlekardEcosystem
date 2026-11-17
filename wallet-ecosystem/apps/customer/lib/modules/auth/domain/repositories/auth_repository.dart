import '../entities/customer.dart';

abstract interface class AuthRepository {
  Future<String> register({
    required String phone,
    required String firstName,
    required String lastName,
  });

  Future<String> verifyOtp({
    required String otpReference,
    required String otpCode,
  });

  Future<Customer> completeRegistration({
    required String registrationToken,
    required String pin,
    String? email,
  });

  Future<({String challengeToken, String method})> initiateLogin({
    required String phone,
    required String method,
  });

  Future<Customer> verifyLogin({
    required String challengeToken,
    required String pin,
  });

  Future<void> logout();

  Future<String> refreshToken(String refreshToken);

  Future<String?> initiatePinReset(String phone);

  Future<void> completePinReset({
    required String challengeId,
    required String otp,
    required String newPin,
  });

  Future<void> changePin({
    required String currentPin,
    required String newPin,
  });

  Future<void> registerPushToken({
    required String deviceId,
    required String fcmToken,
  });

  Future<void> unregisterPushToken(String deviceId);
}
