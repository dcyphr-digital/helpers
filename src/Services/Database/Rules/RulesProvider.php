<?php

namespace DcyphrDigital\Helpers\Services\Database\Rules;

use function DcyphrDigital\Helpers\Services\Rules\resolve;

class RulesProvider
{
    public const string IF_NULL_THEN_UPDATE = 'if_null_then_update';

    public function __construct(protected string $rule) {}

    public function execute(): mixed
    {
        $class = match ($this->rule) {
            self::IF_NULL_THEN_UPDATE => IfNullThenUpdate::class,
            default                   => null,
        };

        if (is_null($class)) {
            return null;
        }

        return resolve($class);
    }
}
