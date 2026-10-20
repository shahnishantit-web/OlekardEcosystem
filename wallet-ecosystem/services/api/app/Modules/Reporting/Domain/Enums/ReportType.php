<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Enums;

enum ReportType: string
{
    case UserReport            = 'user-report';
    case WalletReport          = 'wallet-report';
    case TransactionReport     = 'transaction-report';
    case AgentReport           = 'agent-report';
    case CommissionReport      = 'commission-report';
    case KycReport             = 'kyc-report';
    case ReconciliationReport  = 'reconciliation-report';
    case SettlementReport      = 'settlement-report';
    case RiskReport            = 'risk-report';
    case FinancialSummary      = 'financial-summary';
}
