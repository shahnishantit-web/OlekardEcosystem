import 'package:bcrypt/bcrypt.dart';

class PinService {
  const PinService();

  /// Instance method — used when a PinService ref is available.
  Future<String> hashPin(String pin) async {
    // Never logs PIN value — only the resulting hash is stored
    final salt = BCrypt.gensalt(logRounds: 10);
    return BCrypt.hashpw(pin, salt);
  }

  /// Static convenience alias for call-sites that don't hold a PinService ref.
  static Future<String> hash(String pin) async {
    final salt = BCrypt.gensalt(logRounds: 10);
    return BCrypt.hashpw(pin, salt);
  }

  Future<bool> verifyPin(String pin, String storedHash) async {
    return BCrypt.checkpw(pin, storedHash);
  }
}
