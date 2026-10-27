abstract final class PaymentRoutes {
  static const String historyPath = '/activity';
  static const String detailPath = '/activity/:id';
  static const String receiptPath = '/activity/:id/receipt';

  static String detailRoute(String id) => '/activity/$id';
  static String receiptRoute(String id) => '/activity/$id/receipt';
}
