# Security Baseline

- Centralized OAuth2/OIDC-style identity architecture.
- Authorization Code + PKCE for user-facing clients where applicable.
- Client Credentials for server-to-server integrations where applicable.
- MFA for administration and wallet step-up where required.
- Zero Trust for user-to-service and service-to-service calls.
- TLS 1.2+; TLS 1.3 preferred.
- AES-256-class protection for sensitive data at rest.
- Centralized secrets and key management.
- Security event logging and SIEM integration.
- Risk/fraud rules configurable; values are not invented.
