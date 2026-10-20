class RegisterRequestDto {
  final String phone;
  final String firstName;
  final String lastName;

  const RegisterRequestDto({
    required this.phone,
    required this.firstName,
    required this.lastName,
  });

  Map<String, dynamic> toJson() => {
        'phone': phone,
        'first_name': firstName,
        'last_name': lastName,
      };
}

class LoginRequestDto {
  final String phone;
  final String method;

  const LoginRequestDto({required this.phone, required this.method});

  Map<String, dynamic> toJson() => {'phone': phone, 'method': method};
}

class LoginVerifyDto {
  final String challengeToken;
  final String pinHash;

  const LoginVerifyDto({required this.challengeToken, required this.pinHash});

  Map<String, dynamic> toJson() => {
        'challenge_token': challengeToken,
        'pin_hash': pinHash,
      };
}

class LoginChallengeResponseDto {
  final String challengeToken;
  final String method;
  final int expiresInSeconds;

  const LoginChallengeResponseDto({
    required this.challengeToken,
    required this.method,
    required this.expiresInSeconds,
  });

  factory LoginChallengeResponseDto.fromJson(Map<String, dynamic> json) =>
      LoginChallengeResponseDto(
        challengeToken: json['challenge_token'] as String,
        method: json['method'] as String,
        expiresInSeconds: json['expires_in_seconds'] as int,
      );
}
