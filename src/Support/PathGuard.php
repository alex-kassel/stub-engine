<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Support;

use InvalidArgumentException;

class PathGuard
{
    public const EMPTY_STRING_FALLBACK = '';

    /**
     * Validate that a destination path stays strictly within the target directory.
     *
     * @throws InvalidArgumentException
     */
    public function ensureWithinTargetDirectory(string $targetDir, string $destination): void
    {
        $normalizedTarget = rtrim(str_replace('\\', '/', $targetDir), '/');
        $normalizedDest = str_replace('\\', '/', $destination);

        // If target is current working directory ('.' or empty) and destination is relative
        if (($normalizedTarget === '.' || $normalizedTarget === self::EMPTY_STRING_FALLBACK) && ! str_starts_with($normalizedDest, '/')) {
            $destParts = explode('/', $normalizedDest);
            $depth = 0;
            foreach ($destParts as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    $depth--;
                    if ($depth < 0) {
                        throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
                    }
                } else {
                    $depth++;
                }
            }

            return;
        }

        $canonicalDest = $this->canonicalizeSegments(explode('/', $normalizedDest), $destination, $targetDir);
        $canonicalTarget = $this->canonicalizeSegments(explode('/', $normalizedTarget), $destination, $targetDir, allowEmptyTraversal: true);

        $targetPrefix = (str_starts_with($normalizedTarget, '/') ? '/' : self::EMPTY_STRING_FALLBACK).implode('/', $canonicalTarget);
        $destResolved = (str_starts_with($normalizedDest, '/') ? '/' : self::EMPTY_STRING_FALLBACK).implode('/', $canonicalDest);

        if (! str_starts_with($destResolved, $targetPrefix.'/') && $destResolved !== $targetPrefix) {
            throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
        }
    }

    /**
     * Canonicalize path segments resolving '.' and '..' operations.
     *
     * @param  array<int, string>  $segments
     * @return array<int, string>
     */
    protected function canonicalizeSegments(array $segments, string $destination, string $targetDir, bool $allowEmptyTraversal = false): array
    {
        $canonical = [];

        foreach ($segments as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (empty($canonical)) {
                    if ($allowEmptyTraversal) {
                        continue;
                    }
                    throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
                }
                array_pop($canonical);
            } else {
                $canonical[] = $part;
            }
        }

        return $canonical;
    }
}
