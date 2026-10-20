import 'package:dio/dio.dart';

class ApiClient {
  late final Dio _dio;

  ApiClient({required String baseUrl}) {
    _dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 30),
      receiveTimeout: const Duration(seconds: 30),
    ));

    _dio.interceptors.addAll([
      AuthInterceptor(),
      CorrelationIdInterceptor(),
      RetryInterceptor(dio: _dio),
      LoggingInterceptor(),
      ErrorInterceptor(),
    ]);
  }

  Dio get dio => _dio;
}

class AuthInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    // Auth token added by E31 — stub for Platform Foundation
    super.onRequest(options, handler);
  }
}

class CorrelationIdInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['X-Correlation-ID'] ??= _generateCorrelationId();
    super.onRequest(options, handler);
  }

  String _generateCorrelationId() {
    final now = DateTime.now().millisecondsSinceEpoch;
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replaceAllMapped(
      RegExp(r'[xy]'),
      (m) {
        final r = (now + m.start * 16) % 16;
        final v = m.group(0) == 'x' ? r : (r & 0x3 | 0x8);
        return v.toRadixString(16);
      },
    );
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

    super.onError(err, handler);
  }
}

class LoggingInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    // PII masking applied before logging in production — stub for Platform Foundation
    super.onRequest(options, handler);
  }
}

class ErrorInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    super.onError(err, handler);
  }
}
