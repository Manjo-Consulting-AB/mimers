<?php

namespace App\Support\Agent;

use App\Models\Account;
use App\Support\Access\AccessLevel;

/**
 * Jämförelser på agentens nivåstege. `null` är "ingen åtkomst" och är lägre
 * än alla nivåer — samma sak som en saknad rad i `agent_account_grant` och
 * `agent_container_grant`.
 */
final class AgentLevels
{
    /**
     * Planer som ger MCP-åtkomst: Pro och Pro+, även i grundarvarianterna
     * ([[ADR-0087 MCP-server för agenter]] Riktning 4).
     */
    public const PLAN_CODES = ['pro', 'pro_plus', 'pro_founder', 'pro_plus_founder'];

    /**
     * Den lägsta av nivåerna; en `null` bland dem ger `null`.
     */
    public static function min(?string ...$levels): ?string
    {
        $lowest = null;

        foreach ($levels as $level) {
            if ($level === null) {
                return null;
            }

            if ($lowest === null || AccessLevel::rank($level) < AccessLevel::rank($lowest)) {
                $lowest = $level;
            }
        }

        return $lowest;
    }

    /**
     * Kapar `$level` till `$ceiling`. `null` i taket ger `null`.
     */
    public static function cap(?string $level, ?string $ceiling): ?string
    {
        return self::min($level, $ceiling);
    }

    public static function isAbove(string $level, ?string $ceiling): bool
    {
        return $ceiling === null || AccessLevel::rank($level) > AccessLevel::rank($ceiling);
    }

    public static function planIncludesMcp(Account $account): bool
    {
        return in_array($account->currentPlan()->code, self::PLAN_CODES, true);
    }
}
