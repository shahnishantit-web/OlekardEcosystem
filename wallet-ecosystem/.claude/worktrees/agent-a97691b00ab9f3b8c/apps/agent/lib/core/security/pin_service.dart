import 'package:bcrypt/bcrypt.dart';

class PinService {
  // PIN hashing. The raw PIN value is never stored or logged.
  static String hash(String pin) {
    final salt = BCrypt.gensalt(logRounds: 10);
    return BCrypt.hashpw(pin, salt);
  }

  static bool verify(String pin, String storedHash) {
    return BCrypt.checkpw(pin, storedHash);
  }
}
