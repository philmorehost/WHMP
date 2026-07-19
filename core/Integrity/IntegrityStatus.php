<?php

declare(strict_types=1);

namespace CodeVault\Integrity;

enum IntegrityStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
}
