class ApiResponse<T> {
  const ApiResponse({
    required this.success,
    required this.data,
    required this.correlationId,
    this.error,
    this.meta,
  });

  final bool success;
  final T? data;
  final ApiError? error;
  final String correlationId;
  final Map<String, dynamic>? meta;

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(Object?) fromJsonT,
  ) {
    return ApiResponse<T>(
      success: json['success'] as bool,
      data: json['data'] != null ? fromJsonT(json['data']) : null,
      error: json['error'] != null
          ? ApiError.fromJson(json['error'] as Map<String, dynamic>)
          : null,
      correlationId: json['correlation_id'] as String? ?? '',
      meta: json['meta'] as Map<String, dynamic>?,
    );
  }
}

class ApiError {
  const ApiError({
    required this.code,
    required this.message,
    this.details = const [],
  });

  final String code;
  final String message;
  final List<ApiErrorDetail> details;

  factory ApiError.fromJson(Map<String, dynamic> json) {
    return ApiError(
      code: json['code'] as String,
      message: json['message'] as String,
      details: (json['details'] as List<dynamic>? ?? [])
          .map((d) => ApiErrorDetail.fromJson(d as Map<String, dynamic>))
          .toList(),
    );
  }
}

class ApiErrorDetail {
  const ApiErrorDetail({
    required this.code,
    required this.message,
    this.field,
  });

  final String code;
  final String message;
  final String? field;

  factory ApiErrorDetail.fromJson(Map<String, dynamic> json) {
    return ApiErrorDetail(
      code: json['code'] as String,
      message: json['message'] as String,
      field: json['field'] as String?,
    );
  }
}
