<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    /** Not configured in this environment, e.g. no Valkey in DDEV */
    case Skipped = 'skipped';
}
