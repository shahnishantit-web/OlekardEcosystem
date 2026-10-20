import 'package:dio/dio.dart';

/// T177 — Connectivity-aware degradation for Agent auth flows.
/// Detects network unavailability from Dio error types and maps them
/// to a user-friendly offline error code so screens can show an offline
/// banner before (or after) an API call instead of a generic crash.
abstract final class ConnectivityService {
  static const offlineCode = 'NETWORK_OFFLINE';
  static const offlineMessage =
      'No internet connection. Check your network and try again.';

  static bool isOfflineError(DioException e) =>
      e.type == DioExceptionType.connectionError ||
      e.type == DioExceptionType.connectionTimeout ||
      e.type == DioExceptionType.receiveTimeout ||
      e.type == DioExceptionType.sendTimeout;
}
