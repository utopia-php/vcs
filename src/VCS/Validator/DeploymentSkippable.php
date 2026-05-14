<?php

namespace Utopia\VCS\Validator;

use Utopia\Validator\Contains;

class DeploymentSkippable extends Contains
{
    private const PATTERNS = [
        '[skip ci]',
    ];

    public function __construct()
    {
        parent::__construct(self::PATTERNS);
    }
}
