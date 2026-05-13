<?php

namespace Utopia\VCS\Validator;

use Utopia\Validator;

class DeploymentSkippable extends Validator
{
    private const PATTERNS = [
        '[skip ci]',
        '[ci skip]',
        '[no ci]',
        '[skip action]',
        '[action skip]',
        '[no action]',
        '[skip actions]',
        '[actions skip]',
        '[no actions]',
        '[skip deploy]',
        '[deploy skip]',
        '[no deploy]',
        '[skip appwrite]',
        '[appwrite skip]',
        '[no appwrite]',
    ];

    public function getDescription(): string
    {
        return 'Value must be a commit message containing a skip directive such as [skip ci] or [no deploy].';
    }

    public function isValid($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $value = strtolower($value);

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
