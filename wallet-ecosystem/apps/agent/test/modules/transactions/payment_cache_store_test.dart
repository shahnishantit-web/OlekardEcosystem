// ignore_for_file: argument_type_not_assignable
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/annotations.dart';
import 'package:mockito/mockito.dart';

import 'package:wallet_agent/modules/transactions/infrastructure/payment_cache_store.dart';

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

      await store.writeHistoryPage('agent1', '{"data":[]}');
      final entry = await store.readHistoryPage('agent1');

      expect(entry, isNotNull);
      expect(entry!.rawJson, '{"data":[]}');
      expect(entry.isStale, isFalse);
    });

    test('returns null when nothing cached', () async {
      when(mockStorage.read(key: anyNamed('key')))
          .thenAnswer((_) async => null);

      final entry = await store.readHistoryPage('nobody');
      expect(entry, isNull);
    });
  });

  group('PaymentCacheStore.clearAll (SC-10)', () {
    test('deletes all payment_ prefixed keys on logout', () async {
      final stored = {
        'payment_history_agent1': 'x',
        'payment_detail_intent-1': 'y',
        'auth_token': 'keep-me',
        'payment_detail_intent-2': 'z',
      };
      when(mockStorage.readAll()).thenAnswer((_) async => stored);
      when(mockStorage.delete(key: anyNamed('key'))).thenAnswer((_) async {});

      await store.clearAll();

      verify(mockStorage.delete(key: 'payment_history_agent1')).called(1);
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
      when(mockStorage.readAll()).thenThrow(Exception('storage error'));

      await expectLater(store.clearAll(), completes);
    });
  });

  group('PaymentCacheEntry serialization', () {
    test('round-trips through toStorageString / fromStorageString', () {
      final now = DateTime.now();
      final entry = PaymentCacheEntry(
        rawJson: '{"agent":true}',
        cachedAt: now,
        staleAfterSeconds: 60,
      );

      final restored =
          PaymentCacheEntry.fromStorageString(entry.toStorageString());

      expect(restored, isNotNull);
      expect(restored!.rawJson, entry.rawJson);
      expect(restored.staleAfterSeconds, 60);
    });

    test('fromStorageString returns null for invalid input', () {
      expect(PaymentCacheEntry.fromStorageString('{invalid}'), isNull);
    });
  });
}
