class ApiResponse<T> {
  final T data;
  final String? message;

  const ApiResponse({required this.data, this.message});

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic) fromJsonT,
  ) {
    return ApiResponse(
      data: fromJsonT(json['data']),
      message: json['message'] as String?,
    );
  }
}

class PaginatedResult<T> {
  final List<T> data;
  final int currentPage;
  final int total;
  final int lastPage;

  const PaginatedResult({
    required this.data,
    required this.currentPage,
    required this.total,
    required this.lastPage,
  });

  bool get hasNextPage => currentPage < lastPage;

  factory PaginatedResult.fromJson(
    Map<String, dynamic> json,
    T Function(Map<String, dynamic>) fromJsonT,
  ) {
    final items = (json['data'] as List<dynamic>)
        .map((e) => fromJsonT(e as Map<String, dynamic>))
        .toList();
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};
    return PaginatedResult(
      data: items,
      currentPage: (meta['current_page'] ??
          meta['page'] ??
          json['current_page'] ??
          1) as int,
      total: (meta['total'] ?? json['total'] ?? items.length) as int,
      lastPage:
          (meta['last_page'] ?? meta['pages'] ?? json['last_page'] ?? 1) as int,
    );
  }
}
