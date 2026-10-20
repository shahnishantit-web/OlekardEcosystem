import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:wallet_customer/core/api/api_client.dart';
import 'package:wallet_customer/core/security/secure_storage_service.dart';
import 'package:wallet_customer/modules/auth/data/repositories/auth_repository_impl.dart';

class _Adapter implements HttpClientAdapter {
  final int status;
  final Map<String, Object?> body;
  final calls = <RequestOptions>[];
  _Adapter(this.status, this.body);

  @override
  Future<ResponseBody> fetch(RequestOptions options,
      Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    calls.add(options);
    return ResponseBody.fromString(jsonEncode(body), status, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType]
    });
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late ApiClient client;
  late AuthRepositoryImpl repository;
  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    client = ApiClient(baseUrl: 'https://api.example.test');
    repository = AuthRepositoryImpl(
        apiClient: client, storage: const SecureStorageService());
  });
  tearDown(() => client.dio.close(force: true));

  test('login sends the entered PIN and persists returned session tokens',
      () async {
    final adapter = _Adapter(200, {
      'access_token': 'access-test',
      'refresh_token': 'refresh-test',
      'expires_in': 900,
      'customer': {
        'id': 'customer-test',
        'phone': '+26771000001',
        'first_name': 'Test',
        'last_name': 'Customer',
        'created_at': '2026-10-18T00:00:00Z',
      },
    });
    client.dio.httpClientAdapter = adapter;
    final customer = await repository.verifyLogin(
        challengeToken: 'challenge', pin: '123456');
    expect(customer.phone, '+26771000001');
    expect(adapter.calls.single.data,
        {'challenge_token': 'challenge', 'pin': '123456'});
    expect(await const SecureStorageService().readAccessToken(), 'access-test');
    expect(
        await const SecureStorageService().readRefreshToken(), 'refresh-test');
  });

  test('wrong PIN is shown once without refreshing an old session', () async {
    FlutterSecureStorage.setMockInitialValues({'refresh_token': 'old-session'});
    final adapter = _Adapter(401, {
      'error': {'code': 'IDENTITY_002', 'message': 'Invalid credentials.'},
    });
    client.dio.httpClientAdapter = adapter;
    await expectLater(
        repository.verifyLogin(challengeToken: 'challenge', pin: '654321'),
        throwsA(isA<AuthException>()
            .having((e) => e.message, 'message', 'Invalid credentials.')));
    expect(adapter.calls, hasLength(1));
  });

  test('server failures never automatically resubmit a PIN', () async {
    final adapter = _Adapter(500, {
      'error': {'message': 'Service temporarily unavailable.'},
    });
    client.dio.httpClientAdapter = adapter;
    await expectLater(
        repository.verifyLogin(challengeToken: 'challenge', pin: '123456'),
        throwsA(isA<AuthException>()));
    expect(adapter.calls, hasLength(1));
  });

  test('structured validation errors do not cause a null cast exception',
      () async {
    client.dio.httpClientAdapter = _Adapter(422, {
      'error': {
        'code': 'VALIDATION_ERROR',
        'message': 'The given data was invalid.',
        'details': []
      },
    });
    await expectLater(
        repository.verifyLogin(challengeToken: 'challenge', pin: ''),
        throwsA(isA<AuthException>().having(
            (e) => e.message, 'message', 'The given data was invalid.')));
  });
}
