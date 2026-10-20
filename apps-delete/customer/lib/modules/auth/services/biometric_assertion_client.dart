/// Client-side TBD-004 biometric abstraction boundary.
/// Raw biometric data MUST NOT be sent to the server (BR-009).
/// Only a cryptographic signed assertion is submitted.
///
/// This interface preserves the TBD-004 boundary on the Flutter side,
/// mirroring the server-side BiometricAssertionContract / StubBiometricAssertionProvider.
/// A production implementation will be wired here once TBD-004 is resolved.

/// Result of a client-side biometric authentication attempt.
class BiometricAssertionResult {
  const BiometricAssertionResult({
    required this.success,
    this.signedAssertion,
    this.credentialId,
    this.failureReason,
  });

  final bool success;

  /// Base64-encoded cryptographic assertion — the ONLY biometric-derived
  /// value transmitted to the backend. Raw biometric data never leaves the device.
  final String? signedAssertion;

  /// Credential ID matching the enrolled FIDO2 / biometric record on the server.
  final String? credentialId;

  final String? failureReason;
}

/// Client-side biometric assertion provider interface (TBD-004 boundary).
abstract class BiometricAssertionClient {
  /// Returns true if biometric authentication is available on this device.
  Future<bool> isAvailable();

  /// Triggers the device biometric prompt and returns a signed assertion.
  /// [nonce] is the server-generated challenge nonce from
  /// `GET /v1/auth/login/biometric/challenge`.
  /// [userId] and [credentialId] identify the enrolled credential.
  ///
  /// Implementations MUST NOT transmit any raw biometric template or image.
  Future<BiometricAssertionResult> authenticate({
    required String nonce,
    required String userId,
    required String credentialId,
  });
}

/// Stub implementation for development and testing.
/// Returns a deterministic signed assertion for test payloads.
/// TBD-004 boundary preserved — replace this class when the SDK is selected.
class StubBiometricAssertionClient implements BiometricAssertionClient {
  const StubBiometricAssertionClient();

  @override
  Future<bool> isAvailable() async => true;

  @override
  Future<BiometricAssertionResult> authenticate({
    required String nonce,
    required String userId,
    required String credentialId,
  }) async {
    // Stub: return a fake assertion for development only.
    return BiometricAssertionResult(
      success: true,
      signedAssertion: 'stub-assertion-$nonce',
      credentialId: credentialId,
    );
  }
}
