import '../../domain/entities/auth_token.dart';
import '../../domain/entities/customer.dart';

class AuthResponseDto {
  final String accessToken;
  final String refreshToken;
  final int expiresIn;
  final Map<String, dynamic> customerData;

  const AuthResponseDto({
    required this.accessToken,
    required this.refreshToken,
    required this.expiresIn,
    required this.customerData,
  });

  factory AuthResponseDto.fromJson(Map<String, dynamic> json) {
    return AuthResponseDto(
      accessToken: json['access_token'] as String,
      refreshToken: json['refresh_token'] as String,
      expiresIn: json['expires_in'] as int,
      customerData: json['customer'] as Map<String, dynamic>,
    );
  }

  AuthToken toAuthToken() => AuthToken(
        accessToken: accessToken,
        refreshToken: refreshToken,
        expiresAt: DateTime.now().add(Duration(seconds: expiresIn)),
      );

  Customer toCustomer() {
    final kycString = customerData['kyc_status'] as String? ?? 'none';
    final kycStatus = KycStatus.values.firstWhere(
      (e) => e.name == kycString,
      orElse: () => KycStatus.none,
    );
    return Customer(
      id: customerData['id'] as String,
      phone: customerData['phone'] as String,
      email: customerData['email'] as String?,
      firstName: customerData['first_name'] as String,
      lastName: customerData['last_name'] as String,
      avatarUrl: customerData['avatar_url'] as String?,
      kycStatus: kycStatus,
      locale: customerData['locale'] as String? ?? 'en',
      createdAt: DateTime.parse(customerData['created_at'] as String),
    );
  }
}
