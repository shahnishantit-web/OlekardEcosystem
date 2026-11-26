import type { LucideIcon } from 'lucide-react';
import {
  Activity,
  ArrowLeftRight,
  Banknote,
  BarChart2,
  Bell,
  Building2,
  Bug,
  Calendar,
  ClipboardCheck,
  ClipboardList,
  Clock,
  CreditCard,
  DollarSign,
  FileText,
  FlaskConical,
  Globe,
  Inbox,
  KeyRound,
  Layers,
  LayoutDashboard,
  Lock,
  Monitor,
  Percent,
  Plug,
  RefreshCw,
  Server,
  Settings,
  Shield,
  ShieldAlert,
  TrendingUp,
  UserCheck,
  Users,
  Wallet,
} from 'lucide-react';

export interface NavItem {
  href: string;
  label: string;
  icon?: LucideIcon;
  requiredPermission?: string;
}

export interface NavGroup {
  kind: 'group';
  label: string;
  icon?: LucideIcon;
  items: NavItem[];
  requiredPermission?: string;
}

export type NavEntry = NavItem | NavGroup;

export function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'kind' in entry && entry.kind === 'group';
}

export interface NavSection {
  title: string;
  items: NavEntry[];
  requiredPermission?: string;
}

export const NAV_SECTIONS: NavSection[] = [
  {
    title: 'Workspace',
    items: [
      { href: '/dashboard', label: 'Overview', icon: LayoutDashboard },
      {
        kind: 'group', label: 'Dashboards', icon: TrendingUp, items: [
          { href: '/financial-dashboard', label: 'Financial Dashboard', icon: TrendingUp },
          { href: '/payment/finance-dashboard', label: 'Payment Finance', icon: DollarSign, requiredPermission: 'payment.history.read.platform' },
        ],
      },
    ],
  },
  {
    title: 'People & Accounts',
    items: [
      {
        kind: 'group', label: 'Customers', icon: Users, items: [
          { href: '/users', label: 'Users', icon: Users },
          { href: '/kyc', label: 'KYC Reviews', icon: ClipboardCheck },
        ],
      },
      {
        kind: 'group', label: 'Agents', icon: UserCheck, items: [
          { href: '/agents', label: 'Agent Management', icon: UserCheck },
          { href: '/float', label: 'Agent Float', icon: Banknote },
        ],
      },
      {
        kind: 'group', label: 'Wallets & Transactions', icon: Wallet, items: [
          { href: '/wallets', label: 'Wallets', icon: Wallet },
          { href: '/transactions', label: 'Transactions', icon: ArrowLeftRight },
        ],
      },
      {
        kind: 'group', label: 'Access & Devices', icon: Monitor, items: [
          { href: '/devices', label: 'Devices', icon: Monitor },
          { href: '/sessions', label: 'Sessions', icon: Clock },
        ],
      },
    ],
  },
  {
    title: 'Payments & Settlement',
    items: [
      {
        kind: 'group', label: 'Payment Activity', icon: ArrowLeftRight, items: [
          { href: '/payment/history', label: 'Payment History', icon: ArrowLeftRight, requiredPermission: 'payment.history.read.platform' },
          { href: '/payment/refund-approval-queue', label: 'Refund Approvals', icon: ClipboardCheck, requiredPermission: 'payment.refunds.approve' },
          { href: '/payment/payout/requests', label: 'Payout Requests', icon: DollarSign, requiredPermission: 'payment.payout.view' },
          { href: '/payment/dispute-cases', label: 'Dispute Cases', icon: ShieldAlert, requiredPermission: 'payment.disputes.view' },
          { href: '/payment/reports', label: 'Payment Reports', icon: BarChart2, requiredPermission: 'payment.export.read.organisation' },
        ],
      },
      {
        kind: 'group', label: 'Payment Setup', icon: CreditCard, items: [
          { href: '/payments/banks', label: 'Bank Integrations', icon: CreditCard, requiredPermission: 'payment.configuration' },
          { href: '/payment/providers', label: 'Payment Providers', icon: CreditCard, requiredPermission: 'payment.providers.read' },
          { href: '/payments/providers', label: 'Provider Operations', icon: Server, requiredPermission: 'payment.providers.read' },
          { href: '/payments/config/modules', label: 'Module Configuration', icon: Layers, requiredPermission: 'payment.providers.read' },
          { href: '/payments/providers/approvals', label: 'Provider Approvals', icon: ClipboardList, requiredPermission: 'payment.config.approve_high_risk' },
          { href: '/payment/reconciliation/exceptions', label: 'Payment Exceptions', icon: CreditCard, requiredPermission: 'payment.reconciliation.view' },
          { href: '/fees/rules', label: 'Fee Rules', icon: Percent },
        ],
      },
      {
        kind: 'group', label: 'Reconciliation & Reports', icon: RefreshCw, items: [
          { href: '/reconciliation/batches', label: 'Reconciliation Batches', icon: RefreshCw },
          { href: '/reconciliation/settlements', label: 'Settlements', icon: DollarSign },
          { href: '/reports', label: 'Reports', icon: BarChart2 },
          { href: '/reports/schedules', label: 'Report Schedules', icon: Calendar },
          { href: '/reports/reconciliation', label: 'Reconciliation Report', icon: FileText },
          { href: '/reports/settlement', label: 'Settlement Report', icon: FileText },
        ],
      },
    ],
  },
  {
    title: 'Community & Events',
    items: [
      {
        kind: 'group', label: 'Organisations', icon: Building2, items: [
          { href: '/organisations/onboarding', label: 'Organisation Onboarding', icon: Building2 },
          { href: '/organisations/memberships', label: 'Organisation Memberships', icon: Users },
        ],
      },
      {
        kind: 'group', label: 'Events', icon: Calendar, items: [
          { href: '/community/events', label: 'Event Authoring & Review', icon: Calendar },
          { href: '/community/sponsor-reports', label: 'Sponsor Event Reports', icon: BarChart2 },
        ],
      },
      { href: '/community/funeral-assistance', label: 'Funeral Assistance', icon: Users },
      { href: '/support/cases', label: 'Support Cases', icon: Inbox, requiredPermission: 'support.investigate' },
    ],
  },
  {
    title: 'Risk & Security',
    items: [
      {
        kind: 'group', label: 'Security', icon: ShieldAlert, items: [
          { href: '/security/overview', label: 'Security Overview', icon: Shield, requiredPermission: 'security.alerts.read' },
          { href: '/security/dashboard', label: 'Security Dashboard', icon: ShieldAlert, requiredPermission: 'security.alerts.read' },
          { href: '/security/findings', label: 'Vulnerabilities', icon: Bug, requiredPermission: 'security.findings.read' },
          { href: '/security/events', label: 'Security Events', icon: ShieldAlert, requiredPermission: 'security.events.read' },
          { href: '/platform/security-config', label: 'Security Policies', icon: Lock },
        ],
      },
      { href: '/compliance', label: 'Compliance', icon: Shield },
      { href: '/risk-dashboard', label: 'Risk Dashboard', icon: ShieldAlert },
    ],
  },
  {
    title: 'Administration',
    items: [
      {
        kind: 'group', label: 'Notifications', icon: Bell, items: [
          { href: '/notifications', label: 'Notification Center', icon: Bell, requiredPermission: 'notifications.templates.read' },
          { href: '/notifications/templates', label: 'Templates', icon: Inbox, requiredPermission: 'notifications.templates.read' },
          { href: '/notifications/log', label: 'Delivery Log', icon: Inbox, requiredPermission: 'notifications.log.read' },
          { href: '/notifications/rules', label: 'Rules', icon: Settings, requiredPermission: 'notifications.rules.read' },
        ],
      },
      {
        kind: 'group', label: 'Access & Governance', icon: KeyRound, items: [
          { href: '/roles', label: 'Roles', icon: KeyRound, requiredPermission: 'adminapp.roles.manage' },
          { href: '/audit', label: 'Audit Events', icon: FileText },
          { href: '/configuration', label: 'Configuration', icon: Settings },
        ],
      },
      {
        kind: 'group', label: 'Integrations & Operations', icon: Plug, items: [
          { href: '/integrations', label: 'Integrations', icon: Plug, requiredPermission: 'integrations.manage' },
          { href: '/operations/dead-letter', label: 'Dead Letter Queue', icon: Inbox, requiredPermission: 'operations.dead_letter.manage' },
          { href: '/ops', label: 'Ops Center', icon: Activity },
          { href: '/qa', label: 'QA Dashboard', icon: FlaskConical, requiredPermission: 'qa.execute' },
        ],
      },
      {
        kind: 'group', label: 'Currencies', icon: Globe, items: [
          { href: '/multi-currency/currencies', label: 'Currencies', icon: Globe, requiredPermission: 'multi_currency.manage_currencies' },
          { href: '/multi-currency/fx-rates', label: 'FX Rates', icon: Globe, requiredPermission: 'multi_currency.configure_rate' },
        ],
      },
    ],
  },
];
