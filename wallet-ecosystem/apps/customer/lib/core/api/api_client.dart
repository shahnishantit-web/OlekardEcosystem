import 'dart:async';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';
import '../security/secure_storage_service.dart';

class ApiClient {
  late final Dio _dio;
  late final SecureStorageService _storage;

  ApiClient({required String baseUrl}) {
    _storage = const SecureStorageService();
    _dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 30),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
    ));

    _dio.interceptors.addAll([
      AuthInterceptor(_dio, _storage),
      CorrelationIdInterceptor(),
      RetryInterceptor(dio: _dio),
      LoggingInterceptor(),
      ErrorInterceptor(),
    ]);
  }

  Dio get dio => _dio;
}

class AuthInterceptor extends Interceptor {
  final Dio _dio;
  final SecureStorageService _storage;

  bool _isRefreshing = false;
  final List<Completer<void>> _refreshQueue = [];

  // Used by step-up auth — set by navigating to /step-up-pin overlay
  static String? pendingStepUpToken;

  AuthInterceptor(this._dio, this._storage);

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await _storage.readAccessToken();
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    if (pendingStepUpToken != null) {
      options.headers['X-StepUp-Token'] = pendingStepUpToken;
      pendingStepUpToken = null;
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final statusCode = err.response?.statusCode;

    // Step-up authentication challenge
    if (statusCode == 403) {
      final body = err.response?.data;
      if (body is Map && body['error'] == 'step_up_required') {
        // Signal the router to show the step-up PIN overlay
        // The overlay will set pendingStepUpToken and trigger retry via navigatorKey
        handler.next(err);
        return;
      }
    }

    // 401 — token refresh
    final isLoginRequest =
        RegExp(r'/auth/login(?:/verify)?/?$').hasMatch(err.requestOptions.path);
    if (statusCode == 401 &&
        !isLoginRequest &&
        !err.requestOptions.path.contains('/auth/refresh')) {
      if (_isRefreshing) {
        final completer = Completer<void>();
        _refreshQueue.add(completer);
        await completer.future;
        err.requestOptions.headers['Authorization'] =
            'Bearer ${await _storage.readAccessToken()}';
        try {
          final retried = await _dio.fetch(err.requestOptions);
          handler.resolve(retried);
        } catch (e) {
          handler.next(err);
        }
        return;
      }

      _isRefreshing = true;
      try {
        final refreshToken = await _storage.readRefreshToken();
        if (refreshToken == null) {
          handler.next(err);
          return;
        }

        final refreshResponse = await _dio.post(
          '/v1/customer/auth/refresh',
          data: {'refresh_token': refreshToken},
          options: Options(headers: {}),
        );

        final newAccessToken = refreshResponse.data['access_token'] as String;
        await _storage.writeAccessToken(newAccessToken);

        for (final c in _refreshQueue) {
          c.complete();
        }
        _refreshQueue.clear();

        err.requestOptions.headers['Authorization'] = 'Bearer $newAccessToken';
        final retried = await _dio.fetch(err.requestOptions);
        handler.resolve(retried);
      } catch (_) {
        for (final c in _refreshQueue) {
          c.completeError('refresh_failed');
        }
        _refreshQueue.clear();
        handler.next(err);
      } finally {
        _isRefreshing = false;
      }
      return;
    }

    handler.next(err);
  }
}

class CorrelationIdInterceptor extends Interceptor {
  static const _uuid = Uuid();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Correlation-Id'] ??= _uuid.v4();
    handler.next(options);
  }
}

class RetryInterceptor extends Interceptor {
  final Dio dio;
  static const int maxRetries = 3;

  RetryInterceptor({required this.dio});

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final retryCount = err.requestOptions.extra['retry_count'] as int? ?? 0;

    if (err.response?.statusCode != null &&
        !err.requestOptions.path.contains('/auth/') &&
        err.response!.statusCode! >= 500 &&
        retryCount < maxRetries) {
      err.requestOptions.extra['retry_count'] = retryCount + 1;
      Future.delayed(Duration(milliseconds: 200 * (retryCount + 1)), () {
        dio.fetch(err.requestOptions).then(
              (r) => handler.resolve(r),
              onError: (e) => handler.next(e as DioException),
            );
      });
      return;
    }

    handler.next(err);
  }
}

class LoggingInterceptor extends Interceptor {
  static final _sensitivePattern = RegExp(
    r'password|pin|secret|token|card|cvv|otp|ssn|auth',
    caseSensitive: false,
  );

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    assert(() {
      final sanitized = Map<String, dynamic>.from(options.headers)
        ..removeWhere((k, _) => _sensitivePattern.hasMatch(k));
      // ignore: avoid_print
      print('[API] ${options.method} ${options.path} headers: $sanitized');
      return true;
    }());
    handler.next(options);
  }
}

class ErrorInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    handler.next(err);
  }
}
