<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

/**
 * Semantic yes/no judgment over a value — the check ordinary code cannot
 * express ("is this spam?", "is this a real support question?"). Consumed
 * by {@see Schema::satisfies()}.
 *
 * Bound in DI as `Judge::class` to {@see JevJudge} when `TYPESAFE_API_KEY`
 * is set. Tests / other backends implement this directly.
 */
interface Judge
{
    /**
     * Probability (0–1) that `$condition` holds for `$state`.
     *
     * @throws JudgeException when the backend cannot answer
     */
    public function probability(string $condition, mixed $state): float;
}
