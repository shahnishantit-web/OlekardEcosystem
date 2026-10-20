import 'dart:math' as math;

/// Utilities for E01 auth flows.
abstract final class AuthUtils {
  /// Generates a UUID-v4-like string suitable for idempotency keys.
  /// Does not use an external package; mirrors the approach in CorrelationIdInterceptor.
  static String generateIdempotencyKey() {
    final rng = math.Random.secure();
    final bytes = List<int>.generate(16, (_) => rng.nextInt(256));

    // Set version (4) and variant bits per RFC 4122.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-'
        '${hex.substring(8, 12)}-'
        '${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-'
        '${hex.substring(20)}';
  }
}
