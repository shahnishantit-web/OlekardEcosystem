<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Application\EventHandlers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** Restores the typed contracts consumed by the activity projection listeners. */
final class TransactionOutboxSubscriber
{
    public static function register(array $eventClasses): void
    {
        $contracts = [];
        foreach ($eventClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $contracts[$reflection->newInstanceWithoutConstructor()->eventName()] = $reflection;
        }
        foreach (['Funding.*', 'Transfer.*', 'Withdrawal.*', 'Service.Payment.*'] as $pattern) {
            Event::listen($pattern, function (string $name, array $payload) use ($contracts): void {
                if (! isset($contracts[$name])) return;
                $reflection = $contracts[$name];
                $arguments = [];
                foreach ($reflection->getConstructor()->getParameters() as $parameter) {
                    $key = Str::snake($parameter->getName());
                    if (! array_key_exists($key, $payload)) {
                        throw new \UnexpectedValueException("Missing {$key} in {$name} outbox payload.");
                    }
                    $arguments[] = $payload[$key];
                }
                Event::dispatch($reflection->newInstanceArgs($arguments));
            });
        }
    }
}
