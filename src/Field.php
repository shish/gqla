<?php

declare(strict_types=1);

namespace GQLA;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class Field
{
    /**
     * @param array<string,string> $args
     */
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?array $args = null,
        public ?string $extends = null,
        public ?string $description = null,
        public ?string $deprecationReason = null,
    ) {
    }
}
