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
    return PaginatedResult(
      data: (json['data'] as List).map((e) => fromJsonT(e as Map<String, dynamic>)).toList(),
      currentPage: json['current_page'] as int,
      total: json['total'] as int,
      lastPage: json['last_page'] as int,
    );
  }
}
