import 'package:firebase_messaging/firebase_messaging.dart';

class DeepLinkHandler {
  static String? routeForMessage(RemoteMessage message) {
    final type = message.data['type'] as String?;
    switch (type) {
      case 'transaction':
        final id = message.data['transaction_id'];
        return id != null ? '/home/transactions/$id' : '/home/transactions';
      case 'transfer':
        final id = message.data['transaction_id'];
        return id != null ? '/home/transactions/$id' : '/home/transactions';
      case 'support_reply':
        final id = message.data['ticket_id'];
        return id != null ? '/support/$id' : '/support';
      case 'kyc_update':
        return '/profile';
      case 'alert':
        return '/home/notifications';
      default:
        return null;
    }
  }
}
