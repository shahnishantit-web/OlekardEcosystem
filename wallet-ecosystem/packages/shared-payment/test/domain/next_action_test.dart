import 'package:flutter_test/flutter_test.dart';
import 'package:shared_payment/src/domain/models/next_action.dart';

void main() {
  group('NextAction.fromJson', () {
    test('parses wallet_pin', () {
      final action = NextAction.fromJson({'next_action_type': 'wallet_pin'});
      expect(action, isA<WalletPinAction>());
    });

    test('parses redirect with allowedReturnSchemes', () {
      final action = NextAction.fromJson({
        'next_action_type': 'redirect',
        'next_action_data': {
          'redirect_url': 'https://bank.example.com/pay',
          'allowed_return_schemes': ['olekard', 'wallet-customer'],
        },
      });
      expect(action, isA<RedirectAction>());
      final r = action as RedirectAction;
      expect(r.redirectUrl, 'https://bank.example.com/pay');
      expect(r.allowedReturnSchemes, ['olekard', 'wallet-customer']);
    });

    test('parses sdk_launch', () {
      final action = NextAction.fromJson({
        'next_action_type': 'sdk_launch',
        'next_action_data': {
          'sdk_id': 'fnb-sdk',
          'sdk_params': {'merchant_id': 'M123'},
        },
      });
      expect(action, isA<SdkLaunchAction>());
      final s = action as SdkLaunchAction;
      expect(s.sdkId, 'fnb-sdk');
      expect(s.sdkParams['merchant_id'], 'M123');
    });

    test('parses app_switch', () {
      final action = NextAction.fromJson({
        'next_action_type': 'app_switch',
        'next_action_data': {
          'deep_link_url': 'fnbapp://pay?ref=123',
          'fallback_url': 'https://fnb.co.bw',
        },
      });
      expect(action, isA<AppSwitchAction>());
      final a = action as AppSwitchAction;
      expect(a.deepLinkUrl, 'fnbapp://pay?ref=123');
      expect(a.fallbackUrl, 'https://fnb.co.bw');
    });

    test('parses provider_unavailable', () {
      final action = NextAction.fromJson({
        'next_action_type': 'provider_unavailable',
        'next_action_data': {'reason': 'maintenance'},
      });
      expect(action, isA<ProviderUnavailableAction>());
      expect((action as ProviderUnavailableAction).reason, 'maintenance');
    });

    test('unknown type maps to ProviderUnavailableAction', () {
      final action = NextAction.fromJson({'next_action_type': 'exotic_future_type'});
      expect(action, isA<ProviderUnavailableAction>());
      expect((action as ProviderUnavailableAction).reason,
          contains('unknown_next_action'));
    });

    test('missing allowed_return_schemes defaults to empty list', () {
      final action = NextAction.fromJson({
        'next_action_type': 'redirect',
        'next_action_data': {'redirect_url': 'https://bank.co.bw'},
      });
      final r = action as RedirectAction;
      expect(r.allowedReturnSchemes, isEmpty);
    });
  });
}
