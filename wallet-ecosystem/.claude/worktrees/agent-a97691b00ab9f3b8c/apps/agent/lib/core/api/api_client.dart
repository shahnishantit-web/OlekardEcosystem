import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';
import 'package:uuid/uuid.dart';

const String _apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://localhost:8000',
);

Dio createDio(BuildContext? navigatorContext) {
  final dio = Dio(
    BaseOptions(
      baseUrl: _apiBaseUrl,
      connectTimeout: const Duration(seconds: 30),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
    ),
  );

  dio.interceptors.addAll([
    CorrelationIdInterceptor(),
    AuthInterceptor(dio: dio, navigatorContext: navigatorContext),
    RetryInterceptor(dio: dio),
  ]);

  return dio;
}

class CorrelationIdInterceptor extends Interceptor {
  final _uuid = const Uuid();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Correlation-Id'] = _uuid.v4();
    handler.next(options);
  }
}

class AuthInterceptor extends Interceptor {
  final Dio _dio;
  final BuildContext? _navigatorContext;

  Completer<void>? _refreshCompleter;
  bool _isRefreshing = false;

  // In-memory token cache populated by AuthRepositoryImpl on login/refresh.
  static String? _cachedAccessToken;
  static String? _cachedRefreshToken;

  static void updateCachedTokens({
    required String accessToken,
    required String refreshToken,
  }) {
    _cachedAccessToken = accessToken;
    _cachedRefreshToken = refreshToken;
  }

  static void clearCachedTokens() {
    _cachedAccessToken = null;
    _cachedRefreshToken = null;
  }

  AuthInterceptor({required Dio dio, BuildContext? navigatorContext})
      : _dio = dio,
        _navigatorContext = navigatorContext;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final token = _cachedAccessToken;
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode == 401) {
      await _handle401(err, handler);
      return;
    }

    if (err.response?.statusCode == 403) {
      final code = err.response?.data?['code'] as String?;
      if (code == 'step_up_required') {
        _navigatorContext?.push('/step-up-pin');
      }
    }

    handler.next(err);
  }

  Future<void> _handle401(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    if (_isRefreshing) {
      await (_refreshCompleter?.future ?? Future<void>.value());
      _retryRequest(err, handler);
      return;
    }

    _isRefreshing = true;
    _refreshCompleter = Completer<void>();

    try {
      final refreshed = await _refreshToken();
      if (refreshed) {
        _refreshCompleter!.complete();
        _retryRequest(err, handler);
      } else {
        _refreshCompleter!.completeError('refresh_failed');
        handler.next(err);
      }
    } catch (_) {
      _refreshCompleter!.completeError('refresh_failed');
      handler.next(err);
    } finally {
      _isRefreshing = false;
      _refreshCompleter = null;
    }
  }

  void _retryRequest(DioException err, ErrorInterceptorHandler handler) {
    final opts = err.requestOptions;
    final token = _cachedAccessToken;
    if (token != null) {
      opts.headers['Authorization'] = 'Bearer $token';
    }
    _dio.fetch(opts).then(
      (r) => handler.resolve(r),
      onError: (e) => handler.next(e as DioException),
    );
  }

  Future<bool> _refreshToken() async {
    final refreshToken = _cachedRefreshToken;
    if (refreshToken == null) return false;
    try {
      final response = await _dio.post<Map<String, dynamic>>(
        '/v1/agent/auth/refresh',
        data: {'refresh_token': refreshToken},
      );
      final data = response.data!;
      updateCachedTokens(
        accessToken: data['access_token'] as String,
        refreshToken: data['refresh_token'] as String,
      );
      return true;
    } catch (_) {
      return false;
    }
  }
}

class RetryInterceptor extends Interceptor {
  final Dio _dio;
  static const int _maxRetries = 3;

  RetryInterceptor({required Dio dio}) : _dio = dio;

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    final statusCode = err.response?.statusCode;
    final retryCount =
        (err.requestOptions.extra['retry_count'] as int?) ?? 0;

    final isServerError = statusCode != null && statusCode >= 500;
    final shouldRetry = isServerError && retryCount < _maxRetries;

    if (shouldRetry) {
      err.requestOptions.extra['retry_count'] = retryCount + 1;
      await Future<void>.delayed(
        Duration(milliseconds: 300 * (retryCount + 1)),
      );
      try {
        final response = await _dio.fetch(err.requestOptions);
        handler.resolve(response);
        return;
      } catch (e) {
        handler.next(e as DioException);
        return;
      }
    }

    handler.next(err);
  }
}
