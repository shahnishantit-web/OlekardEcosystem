<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Permissions;

final class EventPermissions
{
    public const CREATE = 'events.event.create';

    public const READ = 'events.event.read';

    public const UPDATE = 'events.event.update';

    public const SUBMIT = 'events.event.submit';

    public const REVIEW = 'events.event.review';

    public const PRICE_APPROVE = 'events.price.approve';

    public const CURRENCY_ASSIGN = 'events.currency.assign';

    public const PUBLISH = 'events.event.publish';

    public const SALES_PAUSE = 'events.sales.pause';

    public const SALES_RESUME = 'events.sales.resume';

    public const CANCEL_REQUEST = 'events.event.cancel.request';

    public const CANCEL_APPROVE = 'events.event.cancel.approve';

    public const GATE_ASSIGNMENT_MANAGE = 'events.gate.assignment.manage';

    public const GATE_VALIDATE = 'events.gate.validate';

    public const ATTENDANCE_READ = 'events.attendance.read';

    public const REFUND_REQUEST = 'events.ticket.refund.request';

    public const REFUND_EXCEPTION_REVIEW = 'events.ticket.refund.exception.review';

    public const PAYOUT_REQUEST = 'events.payout.request';

    public const ASSIGNMENT_GRANT = 'events.assignment.grant';

    public const ASSIGNMENT_REVOKE = 'events.assignment.revoke';

    public const ASSIGNMENT_BOOTSTRAP_APPROVE = 'events.assignment.bootstrap.approve';

    public const ASSIGNMENT_BOOTSTRAP_EXECUTE = 'events.assignment.bootstrap.execute';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CREATE, self::READ, self::UPDATE, self::SUBMIT, self::REVIEW,
            self::PRICE_APPROVE, self::CURRENCY_ASSIGN, self::PUBLISH, self::SALES_PAUSE, self::SALES_RESUME,
            self::CANCEL_REQUEST, self::CANCEL_APPROVE, self::GATE_ASSIGNMENT_MANAGE,
            self::GATE_VALIDATE, self::ATTENDANCE_READ, self::REFUND_REQUEST,
            self::REFUND_EXCEPTION_REVIEW, self::PAYOUT_REQUEST,
        ];
    }

    /** @return list<string> */
    public static function management(): array
    {
        return [
            self::ASSIGNMENT_GRANT, self::ASSIGNMENT_REVOKE,
            self::ASSIGNMENT_BOOTSTRAP_APPROVE, self::ASSIGNMENT_BOOTSTRAP_EXECUTE,
        ];
    }
}
