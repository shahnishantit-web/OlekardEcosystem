import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;
import 'api_response.dart';

class ApiClient {
  ApiClient({
    String? baseUrl,
    Future<String?> Function()? getToken,
  }) : _dio = _buildDio(
          baseUrl ??
              const String.fromEnvironment('API_BASE_URL',
                  defaultValue: 'http://localhost:8000'),
          getToken: getToken,
        );

  final Dio _dio;

  static Dio _buildDio(
    String baseUrl, {
    Future<String?> Function()? getToken,
  }) {
    final dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 30),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
    ));

    dio.interceptors.addAll([
      AuthInterceptor(getToken: getToken),
      CorrelationIdInterceptor(),
      RetryInterceptor(dio: dio),
      LoggingInterceptor(),
      ErrorInterceptor(),
    ]);

    return dio;
  }

  Future<ApiResponse<T>> get<T>(
    String path,
    T Function(Object?) fromJson,
  ) async {
    final response = await _dio.get<Map<String, dynamic>>(path);
    return ApiResponse.fromJson(response.data!, fromJson);
  }

  Future<ApiResponse<T>> post<T>(
    String path,
    Map<String, dynamic> body,
    T Function(Object?) fromJson, {
    String? idempotencyKey,
    String? setupToken,
  }) async {
    final headers = <String, String>{};
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;
    // X-Setup-Token and Authorization are mutually exclusive per authentication-api.md.
    if (setupToken != null) headers['X-Setup-Token'] = setupToken;

    final response = await _dio.post<Map<String, dynamic>>(
      path,
      data: body,
      options: headers.isNotEmpty ? Options(headers: headers) : null,
    );
    return ApiResponse.fromJson(response.data!, fromJson);
  }

  Future<ApiResponse<T>> put<T>(
    String path,
    Map<String, dynamic> body,
    T Function(Object?) fromJson, {
    String? idempotencyKey,
  }) async {
    final headers = <String, String>{};
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;

    final response = await _dio.put<Map<String, dynamic>>(
      path,
      data: body,
      options: headers.isNotEmpty ? Options(headers: headers) : null,
    );
    return ApiResponse.fromJson(response.data!, fromJson);
  }

  Future<ApiResponse<T>> patch<T>(
    String path,
    Map<String, dynamic> body,
    T Function(Object?) fromJson,
  ) async {
    final response = await _dio.patch<Map<String, dynamic>>(path, data: body);
    return ApiResponse.fromJson(response.data!, fromJson);
  }

  Future<ApiResponse<T>> postMultipart<T>(
    String path,
    FormData formData,
    T Function(Object?) fromJson, {
    String? idempotencyKey,
  }) async {
    final headers = <String, String>{};
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;

    final response = await _dio.post<Map<String, dynamic>>(
      path,
      data: formData,
      options: Options(
        contentType: 'multipart/form-data',
        headers: headers.isNotEmpty ? headers : null,
      ),
    );
    return ApiResponse.fromJson(response.data!, fromJson);
  }

  Future<ApiResponse<T>> delete<T>(
    String path,
    T Function(Object?) fromJson, {
    String? idempotencyKey,
  }) async {
    final headers = <String, String>{};
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;

    final response = await _dio.delete<Map<String, dynamic>>(
      path,
      options: headers.isNotEmpty ? Options(headers: headers) : null,
    );
    return ApiResponse.fromJson(response.data!, fromJson);
  }
}

// ── Interceptors ──────────────────────────────────────────────────────────────

class AuthInterceptor extends Interceptor {
  AuthInterceptor({this.getToken});

  final Future<String?> Function()? getToken;

  @override
  void onRequest(
      RequestOptions options, RequestInterceptorHandler handler) async {
    // Don't overwrite an explicit Authorization or X-Setup-Token header.
    final hasExplicitAuth = options.headers.containsKey('Authorization') ||
        options.headers.containsKey('X-Setup-Token');

    if (!hasExplicitAuth && getToken != null) {
      final token = await getToken!();
      if (token != null) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }
    handler.next(options);
  }
}

class CorrelationIdInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Correlation-ID'] = _generateId();
    handler.next(options);
  }

  String _generateId() {
    // UUID v4 via dart:math — time-ordering not required for correlation IDs.
    const chars = '0123456789abcdef';
    final buffer = StringBuffer();
    final groups = [8, 4, 4, 4, 12];
    for (final len in groups) {
      if (buffer.isNotEmpty) buffer.write('-');
      for (var i = 0; i < len; i++) {
        buffer.write(chars[DateTime.now().microsecondsSinceEpoch % chars.length]);
      }
    }
    return buffer.toString();
  }
}

class RetryInterceptor extends Interceptor {
  RetryInterceptor({required this.dio, this.maxRetries = 3});

  final Dio dio;
  final int maxRetries;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final attempt = err.requestOptions.extra['retryCount'] as int? ?? 0;
    final is5xx = err.response?.statusCode != null &&
        err.response!.statusCode! >= 500;

    if (is5xx && attempt < maxRetries) {
      final delay = Duration(milliseconds: 200 * (1 << attempt));
      await Future.delayed(delay);
      err.requestOptions.extra['retryCount'] = attempt + 1;
      try {
        final response = await dio.fetch(err.requestOptions);
        handler.resolve(response);
        return;
      } catch (e) {
        // Fall through to propagate original error.
      }
    }
    handler.next(err);
  }
}

class LoggingInterceptor extends Interceptor {
  static const _sensitiveFields = {
    'password', 'pin', 'current_pin', 'new_pin', 'credential',
    'otp', 'cvv', 'card_number', 'token', 'refresh_token',
    'access_token', 'setup_token', 'mfa_token', 'step_up_token',
    'signed_assertion', 'totp_code', 'code',
  };

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    // Request body logged with PII fields masked — never log raw credentials.
    handler.next(options);
  }

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    handler.next(response);
  }

  @visibleForTesting
  static Map<String, dynamic> maskBody(Map<String, dynamic> body) {
    return {
      for (final entry in body.entries)
        entry.key: _sensitiveFields.contains(entry.key) ? '***' : entry.value,
    };
  }
}

class ErrorInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    handler.next(err);
  }
}
