import 'dart:async';
import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';
import '../security/secure_storage_service.dart';
import '../session/session_event_bus.dart';

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

    if (baseUrl.trim().isEmpty) {
      _dio.interceptors.add(InterceptorsWrapper(
        onRequest: (options, handler) {
          handler.reject(DioException(
            requestOptions: options,
            message: 'API_BASE_URL is not configured. Run Flutter with '
                '--dart-define-from-file=config/local.web.json or provide '
                'your deployment configuration.',
            type: DioExceptionType.unknown,
          ));
        },
      ));
    }

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
    final path = err.requestOptions.path;
    final isAuthEndpoint = path.contains('/customer/auth/') ||
        path.contains('/auth/token/refresh') ||
        path.contains('/auth/logout');
    if (statusCode == 401 && isAuthEndpoint) {
      handler.next(err);
      return;
    }

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
    if (statusCode == 401 && !isAuthEndpoint) {
      if (_isRefreshing) {
        final completer = Completer<void>();
        _refreshQueue.add(completer);
        try {
          await completer.future;
        } catch (_) {
          handler.next(err);
          return;
        }
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
          await _expireSession();
          handler.next(err);
          return;
        }

        final refreshResponse = await _dio.post(
          '/v1/auth/token/refresh',
          data: {'refresh_token': refreshToken},
          options: Options(headers: {}),
        );

        final refreshData =
            refreshResponse.data['data'] as Map<String, dynamic>;
        final newAccessToken = refreshData['access_token'] as String;
        await _storage.writeAccessToken(newAccessToken);
        await _storage
            .writeRefreshToken(refreshData['refresh_token'] as String);

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
        await _expireSession();
        handler.next(err);
      } finally {
        _isRefreshing = false;
      }
      return;
    }

    handler.next(err);
  }

  Future<void> _expireSession() async {
    await _storage.deleteAll();
    SessionEventBus.instance.fireLogout();
    SessionEventBus.instance.fireSessionExpired();
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
    final responseError = err.response?.data;
    final isFuneralReadinessGate = responseError is Map &&
        responseError['error'] is Map &&
        responseError['error']['code'] ==
            'FUNERAL_ASSISTANCE_PREREQUISITES_UNRESOLVED';

    if (err.response?.statusCode != null &&
        !err.requestOptions.path.contains('/auth/') &&
        !isFuneralReadinessGate &&
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
