import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

const _keyPrefix = 'payment_';
const _defaultStaleAfterSeconds = 300;

class PaymentCacheEntry {
  final String rawJson;
  final DateTime cachedAt;
  final int staleAfterSeconds;

  const PaymentCacheEntry({
    required this.rawJson,
    required this.cachedAt,
    required this.staleAfterSeconds,
  });

  bool get isStale =>
      DateTime.now().isAfter(cachedAt.add(Duration(seconds: staleAfterSeconds)));

  String toStorageString() => jsonEncode({
        'raw': rawJson,
        'cached_at': cachedAt.toIso8601String(),
        'stale_after': staleAfterSeconds,
      });

  static PaymentCacheEntry? fromStorageString(String s) {
    try {
      final map = jsonDecode(s) as Map<String, dynamic>;
      return PaymentCacheEntry(
        rawJson: map['raw'] as String,
        cachedAt: DateTime.parse(map['cached_at'] as String),
        staleAfterSeconds:
            map['stale_after'] as int? ?? _defaultStaleAfterSeconds,
      );
    } catch (_) {
      return null;
    }
  }
}

/// Encrypted offline cache for payment history items in the agent app.
class PaymentCacheStore {
  final FlutterSecureStorage _storage;

  PaymentCacheStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  String _historyKey(String userId) => '${_keyPrefix}history_$userId';
  String _detailKey(String intentId) => '${_keyPrefix}detail_$intentId';

  Future<void> writeHistoryPage(String userId, String rawJson,
      {int staleAfterSeconds = _defaultStaleAfterSeconds}) async {
    final entry = PaymentCacheEntry(
      rawJson: rawJson,
      cachedAt: DateTime.now(),
      staleAfterSeconds: staleAfterSeconds,
    );
    try {
      await _storage.write(
          key: _historyKey(userId), value: entry.toStorageString());
    } catch (_) {}
  }

  Future<PaymentCacheEntry?> readHistoryPage(String userId) async {
    try {
      final s = await _storage.read(key: _historyKey(userId));
      if (s == null) return null;
      return PaymentCacheEntry.fromStorageString(s);
    } catch (_) {
      return null;
    }
  }

  Future<void> writeDetail(String intentId, String rawJson,
      {int staleAfterSeconds = _defaultStaleAfterSeconds}) async {
    final entry = PaymentCacheEntry(
      rawJson: rawJson,
      cachedAt: DateTime.now(),
      staleAfterSeconds: staleAfterSeconds,
    );
    try {
      await _storage.write(
          key: _detailKey(intentId), value: entry.toStorageString());
    } catch (_) {}
  }

  Future<PaymentCacheEntry?> readDetail(String intentId) async {
    try {
      final s = await _storage.read(key: _detailKey(intentId));
      if (s == null) return null;
      return PaymentCacheEntry.fromStorageString(s);
    } catch (_) {
      return null;
    }
  }

  Future<void> clearAll() async {
    try {
      final all = await _storage.readAll();
      final paymentKeys =
          all.keys.where((k) => k.startsWith(_keyPrefix)).toList();
      await Future.wait(paymentKeys.map((k) => _storage.delete(key: k)));
    } catch (_) {}
  }
}
