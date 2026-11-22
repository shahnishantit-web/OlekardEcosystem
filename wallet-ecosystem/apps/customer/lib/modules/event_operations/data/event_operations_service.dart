import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../../../../core/security/secure_storage_service.dart';

class EventOperationsService {
  EventOperationsService({required String baseUrl})
      : _dio = Dio(BaseOptions(
          baseUrl: baseUrl,
          connectTimeout: const Duration(seconds: 25),
          receiveTimeout: const Duration(seconds: 25),
          headers: const {'Accept': 'application/json'},
        )) {
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.readEventOpsAccessToken();
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
    ));
  }

  final Dio _dio;
  final SecureStorageService _storage = const SecureStorageService();
  static const _uuid = Uuid();

  Future<Map<String, dynamic>> signIn(String email, String password) async {
    await _storage.deleteEventOpsAccessToken();
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/auth/login',
        data: {
          'identifier': email.trim(),
          'credential': password,
          'credential_type': 'password',
          'device_fingerprint': 'olekard-event-operations-mobile',
          'device_name': 'OleKard Event Operations',
          'platform': kIsWeb
              ? 'web'
              : defaultTargetPlatform == TargetPlatform.iOS
                  ? 'ios'
                  : 'android',
        },
        options: Options(headers: {'X-Correlation-Id': _uuid.v4()}),
      );
      final body = response.data ?? const <String, dynamic>{};
      final data = _map(body['data'] ?? body);
      if (data['mfa_required'] == true) {
        return {
          'mfa_required': true,
          'mfa_token': data['mfa_token'],
          'methods': data['methods'] ?? const <String>[],
        };
      }
      final token = data['access_token'];
      if (token is! String || token.isEmpty) {
        throw const EventOperationsException(
          'The server did not return an access token.',
        );
      }
      return await _storeAndValidate(token, data['refresh_token']?.toString());
    } on DioException catch (error) {
      throw EventOperationsException(_errorMessage(error));
    }
  }

  Future<Map<String, dynamic>> verifyMfa(String mfaToken, String code) async {
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/auth/mfa/verify',
        data: {'mfa_token': mfaToken, 'method': 'totp', 'code': code.trim()},
        options: Options(headers: {'X-Correlation-Id': _uuid.v4()}),
      );
      final body = response.data ?? const <String, dynamic>{};
      final data = _map(body['data'] ?? body);
      final token = data['access_token'];
      if (token is! String || token.isEmpty) {
        throw const EventOperationsException(
          'The MFA response did not include an access token.',
        );
      }
      return await _storeAndValidate(token, data['refresh_token']?.toString());
    } on DioException catch (error) {
      throw EventOperationsException(_errorMessage(error));
    }
  }

  Future<Map<String, dynamic>> _storeAndValidate(
      String token, String? refreshToken) async {
    final claims = _claims(token);
    if ((claims['actor_type'] ?? '').toString().toUpperCase() != 'ADMIN') {
      throw const EventOperationsException(
        'Use an event-management admin account to sign in here.',
      );
    }
    final permissions = (claims['permissions'] as List<dynamic>? ?? const [])
        .map((permission) => permission.toString())
        .toList(growable: false);
    if (!permissions.any((permission) => permission.startsWith('events.'))) {
      throw const EventOperationsException(
        'This account has no event-management permissions.',
      );
    }
    await _storage.writeEventOpsAccessToken(token);
    if (refreshToken != null && refreshToken.isNotEmpty) {
      await _storage.writeEventOpsRefreshToken(refreshToken);
    }
    return {'claims': claims, 'permissions': permissions};
  }

  Future<Map<String, dynamic>> currentClaims() async {
    final token = await _storage.readEventOpsAccessToken();
    if (token == null) {
      throw const EventOperationsException('Sign in to continue.');
    }
    return _claims(token);
  }

  Future<void> signOut() async {
    try {
      await _request(() => _dio.post<Object?>('/v1/auth/logout'));
    } catch (_) {
      // Always clear the local session even if the API is unreachable.
    } finally {
      await _storage.deleteEventOpsAccessToken();
    }
  }

  Future<List<Map<String, dynamic>>> organisations() async => _getList(
        '/v1/identity/organisations?stage=active',
      );

  Future<List<Map<String, dynamic>>> events(String organisationId) async =>
      _getList('/v1/events/organisations/$organisationId/drafts');

  Future<Map<String, dynamic>> createEvent(
    String organisationId,
    Map<String, dynamic> payload,
  ) async =>
      _post(
        '/v1/events/organisations/$organisationId/drafts',
        payload,
        idempotent: true,
      );

  Future<Map<String, dynamic>> getEvent(String eventId) async =>
      _get('/v1/events/$eventId');

  Future<Map<String, dynamic>> assignCurrency(
    String eventId, {
    required String code,
    required String approvalReference,
  }) async =>
      _post(
        '/v1/events/$eventId/currency-assignment',
        {
          'currency_code': code.toUpperCase(),
          'approval_reference': approvalReference.trim(),
          'policy_version': 'poc-local-v1',
        },
        idempotent: true,
      );

  Future<Map<String, dynamic>> transition(
    String eventId,
    String action,
    int expectedVersion,
  ) async =>
      _post(
        '/v1/events/$eventId/$action',
        {'expected_version': expectedVersion},
        idempotent: true,
      );

  Future<List<Map<String, dynamic>>> _getList(String path) async {
    final response = await _request(() => _dio.get<Object?>(path));
    final value = _unwrap(response.data);
    if (value is! List) {
      throw const EventOperationsException(
          'Unexpected response from the server.');
    }
    return value.map((item) => _map(item)).toList(growable: false);
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final response = await _request(() => _dio.get<Object?>(path));
    return _map(_unwrap(response.data));
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> body, {
    required bool idempotent,
  }) async {
    final key = _uuid.v4();
    final response = await _request(
      () => _dio.post<Object?>(
        path,
        data: body,
        options: Options(headers: {
          'X-Correlation-Id': _uuid.v4(),
          if (idempotent) 'Idempotency-Key': key,
        }),
      ),
    );
    return _map(_unwrap(response.data));
  }

  Future<Response<Object?>> _request(
    Future<Response<Object?>> Function() send,
  ) async {
    final token = await _storage.readEventOpsAccessToken();
    if (token == null || token.isEmpty) {
      throw const EventOperationsException(
          'Your event-operations session ended. Sign in again.');
    }
    try {
      return await send().timeout(const Duration(seconds: 30));
    } on DioException catch (error) {
      if (error.response?.statusCode == 401 &&
          await _refreshEventOpsSession()) {
        try {
          return await send().timeout(const Duration(seconds: 30));
        } on DioException catch (retryError) {
          throw EventOperationsException(_errorMessage(retryError));
        }
      }
      throw EventOperationsException(_errorMessage(error));
    }
  }

  Future<bool> _refreshEventOpsSession() async {
    final refreshToken = await _storage.readEventOpsRefreshToken();
    if (refreshToken == null || refreshToken.isEmpty) return false;
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/auth/token/refresh',
        data: {'refresh_token': refreshToken},
        options: Options(headers: {'X-Correlation-Id': _uuid.v4()}),
      );
      final body = response.data ?? const <String, dynamic>{};
      final data = _map(body['data'] ?? body);
      final accessToken = data['access_token'];
      final nextRefreshToken = data['refresh_token'];
      if (accessToken is! String || nextRefreshToken is! String) return false;
      await _storage.writeEventOpsAccessToken(accessToken);
      await _storage.writeEventOpsRefreshToken(nextRefreshToken);
      return true;
    } catch (_) {
      await _storage.deleteEventOpsAccessToken();
      return false;
    }
  }

  String _errorMessage(DioException error) {
    final status = error.response?.statusCode;
    final body = error.response?.data;
    if (status == 401) return 'Your session expired. Sign in again.';
    if (body is Map) {
      final root = body['error'];
      if (root is Map && root['message'] is String) {
        final details = root['details'];
        if (details is List && details.isNotEmpty && details.first is Map) {
          final detail = details.first as Map;
          if (detail['message'] is String) return detail['message'] as String;
        }
        return root['message'] as String;
      }
      if (body['message'] is String) return body['message'] as String;
    }
    return error.message ?? 'Could not reach the OleKard API.';
  }

  Object? _unwrap(Object? body) {
    if (body is Map && body.containsKey('data')) return body['data'];
    return body;
  }

  Map<String, dynamic> _map(Object? value) => value is Map
      ? value.map((key, value) => MapEntry(key.toString(), value))
      : <String, dynamic>{};

  Map<String, dynamic> _claims(String token) {
    try {
      final sections = token.split('.');
      if (sections.length != 3) return {};
      final encoded = base64Url.normalize(sections[1]);
      return _map(jsonDecode(utf8.decode(base64Url.decode(encoded))));
    } catch (_) {
      return {};
    }
  }
}

class EventOperationsException implements Exception {
  const EventOperationsException(this.message);
  final String message;
  @override
  String toString() => message;
}
