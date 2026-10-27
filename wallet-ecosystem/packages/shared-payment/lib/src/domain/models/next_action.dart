sealed class NextAction {
  const NextAction();

  static NextAction fromJson(Map<String, dynamic> json) {
    final type = json['next_action_type'] as String?;
    final data = json['next_action_data'] as Map<String, dynamic>? ?? {};
    return switch (type) {
      'wallet_pin' => const WalletPinAction(),
      'redirect'   => RedirectAction.fromJson(data),
      'sdk_launch' => SdkLaunchAction.fromJson(data),
      'app_switch' => AppSwitchAction.fromJson(data),
      'provider_unavailable' => ProviderUnavailableAction(
          reason: data['reason'] as String? ?? 'unavailable'),
      _ => ProviderUnavailableAction(
          reason: 'unknown_next_action: $type'),
    };
  }
}

class WalletPinAction extends NextAction {
  const WalletPinAction();
}

class RedirectAction extends NextAction {
  final String redirectUrl;
  final List<String> allowedReturnSchemes;

  RedirectAction({
    required this.redirectUrl,
    this.allowedReturnSchemes = const [],
  });

  factory RedirectAction.fromJson(Map<String, dynamic> data) {
    return RedirectAction(
      redirectUrl: data['redirect_url'] as String? ?? '',
      allowedReturnSchemes:
          (data['allowed_return_schemes'] as List<dynamic>?)
                  ?.map((e) => e as String)
                  .toList() ??
              const [],
    );
  }
}

class SdkLaunchAction extends NextAction {
  final String sdkId;
  final Map<String, String> sdkParams;

  SdkLaunchAction({required this.sdkId, this.sdkParams = const {}});

  factory SdkLaunchAction.fromJson(Map<String, dynamic> data) {
    final rawParams = data['sdk_params'] as Map<String, dynamic>? ?? {};
    return SdkLaunchAction(
      sdkId:     data['sdk_id'] as String? ?? '',
      sdkParams: rawParams.map((k, v) => MapEntry(k, v.toString())),
    );
  }
}

class AppSwitchAction extends NextAction {
  final String deepLinkUrl;
  final String? fallbackUrl;

  AppSwitchAction({required this.deepLinkUrl, this.fallbackUrl});

  factory AppSwitchAction.fromJson(Map<String, dynamic> data) {
    return AppSwitchAction(
      deepLinkUrl: data['deep_link_url'] as String? ?? '',
      fallbackUrl: data['fallback_url'] as String?,
    );
  }
}

class ProviderUnavailableAction extends NextAction {
  final String reason;

  ProviderUnavailableAction({required this.reason});
}
