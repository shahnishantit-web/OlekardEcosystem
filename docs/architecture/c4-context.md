# C4 Level 1 — System Context Diagram

> This diagram shows the OleKard system in the context of its users and external systems.
> External system names are placeholders — provider selection is TBD (Constitution Principle 2).

```mermaid
graph TB
    Customer["👤 Customer\n(End-user with a wallet)"]
    Agent["👤 Agent\n(Field agent / merchant)"]
    Admin["👤 Administrator\n(Operations / compliance team)"]

    subgraph OleKard["OleKard Platform"]
        System["OleKard System\n(Wallet Ecosystem)"]
    end

    PayNet["[External] Payment Network\n(TBD — provider not selected)"]
    KYCProvider["[External] KYC Provider\n(TBD — provider not selected)"]
    NotifProvider["[External] Notification Provider\n(TBD — SMS/email provider not selected)"]

    Customer -->|"Uses mobile app\n(Flutter)"| System
    Agent -->|"Uses agent app\n(Flutter)"| System
    Admin -->|"Uses admin panel\n(Next.js)"| System

    System -->|"Payment clearing\n(TBD protocol)"| PayNet
    System -->|"Identity verification\n(TBD protocol)"| KYCProvider
    System -->|"Send SMS / email\n(TBD protocol)"| NotifProvider
```

## Notes

- External provider connections are shown as placeholders. No URLs, credentials, or provider names are specified here — those are operational configuration values.
- The OleKard system boundary contains all containers described in `c4-container.md`.
