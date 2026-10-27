abstract final class PaymentRoutes {
  static const String historyPath = '/transactions';
  static const String detailPath = '/transactions/:id';
  static const String receiptPath = '/transactions/:id/receipt';

  static String detailRoute(String id) => '/transactions/$id';
  static String receiptRoute(String id) => '/transactions/$id/receipt';
}
