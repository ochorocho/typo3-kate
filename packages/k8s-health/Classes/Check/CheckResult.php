<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

/**
 * Outcome of one check. $label is a key in the extension's locallang.xlf,
 * $arguments are filled into it with sprintf().
 */
final readonly class CheckResult
{
    /**
     * @param list<string|int> $arguments
     */
    public function __construct(
        public Status $status,
        public string $label,
        public array $arguments = [],
    ) {}

    public static function ok(string $label, string|int ...$arguments): self
    {
        return new self(Status::Ok, $label, array_values($arguments));
    }

    public static function warning(string $label, string|int ...$arguments): self
    {
        return new self(Status::Warning, $label, array_values($arguments));
    }

    public static function error(string $label, string|int ...$arguments): self
    {
        return new self(Status::Error, $label, array_values($arguments));
    }

    public static function skipped(string $label, string|int ...$arguments): self
    {
        return new self(Status::Skipped, $label, array_values($arguments));
    }
}
