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
    return PaginatedResult(
      data: items,
      currentPage: json['current_page'] as int,
      total: json['total'] as int,
      lastPage: json['last_page'] as int,
    );
  }
}
