sealed class DomainDeepLink {
  const DomainDeepLink();
}

final class AvailableLink extends DomainDeepLink {
  final String url;
  final String? label;
  const AvailableLink({required this.url, this.label});
}

final class UnavailableLink extends DomainDeepLink {
  final String reason;
  const UnavailableLink({required this.reason});
}
