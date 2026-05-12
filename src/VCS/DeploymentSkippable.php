<?php

namespace Utopia\VCS;

final class DeploymentSkippable
{
    private function __construct()
    {
    }

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

    public static function isSkippedByCommitMessage(mixed $message): bool
    {
        if (!is_string($message)) {
            return false;
        }

        $message = strtolower($message);

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
