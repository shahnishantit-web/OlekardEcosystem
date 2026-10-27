// ignore_for_file: argument_type_not_assignable
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/annotations.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_customer/modules/transactions/infrastructure/payment_cache_store.dart';

import 'payment_cache_store_test.mocks.dart';

@GenerateMocks([FlutterSecureStorage])
void main() {
  late MockFlutterSecureStorage mockStorage;
  late PaymentCacheStore store;

  setUp(() {
    mockStorage = MockFlutterSecureStorage();
    store = PaymentCacheStore(storage: mockStorage);
  });

  group('PaymentCacheStore.writeHistoryPage / readHistoryPage', () {
    test('writes encoded entry and reads it back', () async {
      String? written;
      when(mockStorage.write(key: anyNamed('key'), value: anyNamed('value')))
          .thenAnswer((inv) async {
        written = inv.namedArguments[const Symbol('value')] as String?;
      });
      when(mockStorage.read(key: anyNamed('key')))
          .thenAnswer((_) async => written);

      await store.writeHistoryPage('user1', '{"data":[]}');
      final entry = await store.readHistoryPage('user1');

      expect(entry, isNotNull);
      expect(entry!.rawJson, '{"data":[]}');
      expect(entry.isStale, isFalse);
    });

    test('returns null when nothing cached', () async {
      when(mockStorage.read(key: anyNamed('key')))
          .thenAnswer((_) async => null);

      final entry = await store.readHistoryPage('ghost');
      expect(entry, isNull);
    });

    test('isStale true when staleAfterSeconds is 0', () async {
      final old = PaymentCacheEntry(
        rawJson: '{}',
        cachedAt: DateTime.now().subtract(const Duration(seconds: 10)),
        staleAfterSeconds: 0,
      );
      when(mockStorage.read(key: anyNamed('key')))
          .thenAnswer((_) async => old.toStorageString());

      final entry = await store.readHistoryPage('user1');
      expect(entry!.isStale, isTrue);
    });
  });

  group('PaymentCacheStore.writeDetail / readDetail', () {
    test('writes and reads detail entry', () async {
      String? written;
      when(mockStorage.write(key: anyNamed('key'), value: anyNamed('value')))
          .thenAnswer((inv) async {
        written = inv.namedArguments[const Symbol('value')] as String?;
      });
      when(mockStorage.read(key: anyNamed('key')))
          .thenAnswer((_) async => written);

      await store.writeDetail('intent-abc', '{"id":"intent-abc"}');
      final entry = await store.readDetail('intent-abc');

      expect(entry, isNotNull);
      expect(entry!.rawJson, '{"id":"intent-abc"}');
    });
  });

  group('PaymentCacheStore.clearAll (SC-10)', () {
    test('deletes all payment_ prefixed keys on logout', () async {
      final stored = {
        'payment_history_user1': 'x',
        'payment_detail_intent-1': 'y',
        'auth_token': 'keep-me',
        'payment_detail_intent-2': 'z',
      };
      when(mockStorage.readAll()).thenAnswer((_) async => stored);
      when(mockStorage.delete(key: anyNamed('key'))).thenAnswer((_) async {});

      await store.clearAll();

      // Only payment_ keys deleted
      verify(mockStorage.delete(key: 'payment_history_user1')).called(1);
      verify(mockStorage.delete(key: 'payment_detail_intent-1')).called(1);
      verify(mockStorage.delete(key: 'payment_detail_intent-2')).called(1);
      verifyNever(mockStorage.delete(key: 'auth_token'));
    });

    test('clearAll is a no-op when no payment keys exist', () async {
      when(mockStorage.readAll())
          .thenAnswer((_) async => {'auth_token': 'keep'});
      when(mockStorage.delete(key: anyNamed('key'))).thenAnswer((_) async {});

      await store.clearAll();

      verifyNever(mockStorage.delete(key: anyNamed('key')));
    });

    test('clearAll silently handles storage errors', () async {
      when(mockStorage.readAll()).thenThrow(Exception('storage unavailable'));

      // Should not throw
      await expectLater(store.clearAll(), completes);
    });
  });

  group('PaymentCacheEntry serialization', () {
    test('round-trips through toStorageString / fromStorageString', () {
      final now = DateTime.now();
      final entry = PaymentCacheEntry(
        rawJson: '{"test":true}',
        cachedAt: now,
        staleAfterSeconds: 120,
      );

      final restored = PaymentCacheEntry.fromStorageString(entry.toStorageString());

      expect(restored, isNotNull);
      expect(restored!.rawJson, entry.rawJson);
      expect(restored.staleAfterSeconds, 120);
      expect(
        restored.cachedAt.millisecondsSinceEpoch,
        closeTo(now.millisecondsSinceEpoch, 999),
      );
    });

    test('fromStorageString returns null for garbage input', () {
      expect(PaymentCacheEntry.fromStorageString('not-json'), isNull);
      expect(PaymentCacheEntry.fromStorageString(''), isNull);
    });
  });
}
