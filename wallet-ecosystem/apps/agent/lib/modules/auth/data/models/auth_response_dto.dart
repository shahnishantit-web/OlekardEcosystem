import 'package:wallet_agent/modules/auth/domain/entities/agent.dart';
import 'package:wallet_agent/modules/auth/domain/entities/auth_token.dart';

class AuthResponseDto {
  final String accessToken;
  final String refreshToken;
  final DateTime expiresAt;
  final AgentDto agent;

  const AuthResponseDto({
    required this.accessToken,
    required this.refreshToken,
    required this.expiresAt,
    required this.agent,
  });

  factory AuthResponseDto.fromJson(Map<String, dynamic> json) {
    return AuthResponseDto(
      accessToken: json['access_token'] as String,
      refreshToken: json['refresh_token'] as String,
      expiresAt: DateTime.parse(json['expires_at'] as String),
      agent: AgentDto.fromJson(json['agent'] as Map<String, dynamic>),
    );
  }

  AuthToken toAuthToken() => AuthToken(
        accessToken: accessToken,
        refreshToken: refreshToken,
        expiresAt: expiresAt,
      );
}

class AgentDto {
  final String id;
  final String phone;
  final String firstName;
  final String lastName;
  final String locationCode;
  final String status;
  final String kycStatus;
  final DateTime createdAt;

  const AgentDto({
    required this.id,
    required this.phone,
    required this.firstName,
    required this.lastName,
    required this.locationCode,
    required this.status,
    required this.kycStatus,
    required this.createdAt,
  });

  factory AgentDto.fromJson(Map<String, dynamic> json) {
    return AgentDto(
      id: json['id'] as String,
      phone: json['phone'] as String,
      firstName: json['first_name'] as String,
      lastName: json['last_name'] as String,
      locationCode: json['location_code'] as String,
      status: json['status'] as String,
      kycStatus: json['kyc_status'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  Agent toEntity() => Agent(
        id: id,
        phone: phone,
        firstName: firstName,
        lastName: lastName,
        locationCode: locationCode,
        status: _parseAgentStatus(status),
        kycStatus: _parseKycStatus(kycStatus),
        createdAt: createdAt,
      );

  static AgentStatus _parseAgentStatus(String s) {
    return switch (s) {
      'active' => AgentStatus.active,
      'suspended' => AgentStatus.suspended,
      _ => AgentStatus.pending,
    };
  }

  static KycStatus _parseKycStatus(String s) {
    return switch (s) {
      'verified' => KycStatus.verified,
      'rejected' => KycStatus.rejected,
      _ => KycStatus.pending,
    };
  }
}
