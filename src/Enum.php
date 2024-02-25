<?php

declare(strict_types=1);

namespace GQLA;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Enum
{
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $deprecationReason = null,
    ) {
    }
}
