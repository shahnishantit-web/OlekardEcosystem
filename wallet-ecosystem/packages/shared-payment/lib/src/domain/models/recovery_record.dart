import 'package:shared_preferences/shared_preferences.dart';

class RecoveryRecord {
  static const kPrefsKey = 'payment_pending_intent_id';

  final String intentId;

  const RecoveryRecord({required this.intentId});

  static Future<RecoveryRecord?> read() async {
    final prefs = await SharedPreferences.getInstance();
    final id = prefs.getString(kPrefsKey);
    return id != null ? RecoveryRecord(intentId: id) : null;
  }

  static Future<void> write(String intentId) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(kPrefsKey, intentId);
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(kPrefsKey);
  }
}
