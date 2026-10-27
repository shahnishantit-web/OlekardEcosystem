import type { LucideIcon } from 'lucide-react';
import {
  LayoutDashboard,
  TrendingUp,
  ShieldAlert,
  Users,
  Monitor,
  Clock,
  ClipboardCheck,
  UserCheck,
  Banknote,
  Wallet,
  ArrowLeftRight,
  RefreshCw,
  Percent,
  FileText,
  Shield,
  BarChart2,
  Globe,
  Settings,
  Plug,
  Inbox,
  Activity,
  FlaskConical,
  Lock,
  Bug,
  KeyRound,
  CreditCard,
  Bell,
  Calendar,
  DollarSign,
  Server,
  Layers,
  ClipboardList,
} from 'lucide-react';

export interface NavItem {
  href: string;
  label: string;
  icon?: LucideIcon;
  requiredPermission?: string;
}

export interface NavSection {
  title: string;
  items: NavItem[];
  requiredPermission?: string;
}

export const NAV_SECTIONS: NavSection[] = [
  {
    title: 'Payment Finance',
    items: [
      {
        href: '/payment/finance-dashboard',
        label: 'Finance Dashboard',
        icon: TrendingUp,
        requiredPermission: 'payment.history.read.platform',
      },
      {
        href: '/payment/history',
        label: 'Payment History',
        icon: ArrowLeftRight,
        requiredPermission: 'payment.history.read.platform',
      },
      {
        href: '/payment/refund-approval-queue',
        label: 'Refund Approvals',
        icon: ClipboardCheck,
        requiredPermission: 'payment.refunds.approve',
      },
      {
        href: '/payment/payout/requests',
        label: 'Payout Requests',
        icon: DollarSign,
        requiredPermission: 'payment.payout.view',
      },
      {
        href: '/payment/dispute-cases',
        label: 'Dispute Cases',
        icon: ShieldAlert,
        requiredPermission: 'payment.disputes.view',
      },
      {
        href: '/payment/reports',
        label: 'Payment Reports',
        icon: BarChart2,
        requiredPermission: 'payment.export.read.organisation',
      },
    ],
  },
  {
    title: 'Workspace',
    items: [
      { href: '/dashboard',           label: 'Dashboard',           icon: LayoutDashboard },
      { href: '/financial-dashboard', label: 'Financial Dashboard', icon: TrendingUp },
      { href: '/risk-dashboard',      label: 'Risk Dashboard',      icon: ShieldAlert },
    ],
  },
  {
    title: 'Operations',
    items: [
      { href: '/users',         label: 'Users',          icon: Users },
      { href: '/devices',       label: 'Devices',        icon: Monitor },
      { href: '/sessions',      label: 'Sessions',       icon: Clock },
      { href: '/kyc',           label: 'KYC Reviews',    icon: ClipboardCheck },
      { href: '/agents',        label: 'Agents',         icon: UserCheck },
      { href: '/float',         label: 'Agent Float',    icon: Banknote },
      { href: '/wallets',       label: 'Wallets',        icon: Wallet },
      { href: '/transactions',  label: 'Transactions',   icon: ArrowLeftRight },
      { href: '/reconciliation/batches',    label: 'Reconciliation', icon: RefreshCw },
      { href: '/reconciliation/settlements', label: 'Settlements',    icon: DollarSign },
      { href: '/fees/rules',                label: 'Fee Rules',      icon: Percent },
      {
        href: '/payments/banks',
        label: 'Bank Integrations',
        icon: CreditCard,
        requiredPermission: 'payment.configuration',
      },
      {
        href: '/payment/providers',
        label: 'Payment Providers',
        icon: CreditCard,
        requiredPermission: 'payment.providers.read',
      },
      {
        href: '/payment/reconciliation/exceptions',
        label: 'Payment Exceptions',
        icon: CreditCard,
        requiredPermission: 'payment.reconciliation.view',
      },
      {
        href: '/payments/providers',
        label: 'Providers',
        icon: Server,
        requiredPermission: 'payment.providers.read',
      },
      {
        href: '/payments/config/modules',
        label: 'Module Config',
        icon: Layers,
        requiredPermission: 'payment.providers.read',
      },
      {
        href: '/payments/providers/approvals',
        label: 'Approval Queue',
        icon: ClipboardList,
        requiredPermission: 'payment.config.approve_high_risk',
      },
    ],
  },
  {
    title: 'Management',
    items: [
      {
        href: '/roles',
        label: 'Roles',
        icon: KeyRound,
        requiredPermission: 'adminapp.roles.manage',
      },
      { href: '/support/cases', label: 'Support & Disputes', icon: Inbox, requiredPermission: 'support.investigate' },
      { href: '/notifications', label: 'Notifications', icon: Bell, requiredPermission: 'notifications.templates.read' },
      { href: '/notifications/templates', label: 'Notification Templates', icon: Inbox, requiredPermission: 'notifications.templates.read' },
      { href: '/notifications/log', label: 'Delivery Log', icon: Inbox, requiredPermission: 'notifications.log.read' },
      { href: '/notifications/rules', label: 'Notification Rules', icon: Settings, requiredPermission: 'notifications.rules.read' },
      { href: '/audit',                   label: 'Audit Events',  icon: FileText },
      { href: '/compliance',              label: 'Compliance',    icon: Shield },
      { href: '/reports',                 label: 'Reports',       icon: BarChart2 },
      { href: '/reports/schedules',       label: 'Report Schedules', icon: Calendar },
      { href: '/reports/reconciliation',  label: 'Reconciliation Report', icon: FileText },
      { href: '/reports/settlement',      label: 'Settlement Report', icon: FileText },
      {
        href: '/security/overview',
        label: 'Security Overview',
        icon: Shield,
        requiredPermission: 'security.alerts.read',
      },
      {
        href: '/security/dashboard',
        label: 'Security',
        icon: ShieldAlert,
        requiredPermission: 'security.alerts.read',
      },
      {
        href: '/security/findings',
        label: 'Vulnerabilities',
        icon: Bug,
        requiredPermission: 'security.findings.read',
      },
      { href: '/multi-currency/currencies', label: 'Currencies', icon: Globe, requiredPermission: 'multi_currency.manage_currencies' },
      { href: '/multi-currency/fx-rates', label: 'FX Rates', icon: Globe, requiredPermission: 'multi_currency.configure_rate' },
      { href: '/configuration',             label: 'Configuration',   icon: Settings },
      { href: '/integrations', label: 'Integrations', icon: Plug, requiredPermission: 'integrations.manage' },
      {
        href: '/operations/dead-letter',
        label: 'Dead Letter',
        icon: Inbox,
        requiredPermission: 'operations.dead_letter.manage',
      },
      { href: '/ops',                     label: 'Ops Center',    icon: Activity },
      {
        href: '/qa',
        label: 'QA Dashboard',
        icon: FlaskConical,
        requiredPermission: 'qa.execute',
      },
      { href: '/platform/security-config', label: 'Security Policies', icon: Lock },
      { href: '/security/events', label: 'Security Events', icon: ShieldAlert, requiredPermission: 'security.events.read' },
    ],
  },
];
