<?php

namespace App\Actions\Agent;

use LogicException;

/**
 * En action som ändrar agenters rättigheter anropades med en agent som
 * avsändare ([[ADR-0087 MCP-server för agenter]] Riktning 7, *Inga egna
 * rättigheter*). Det är ett programmeringsfel, inte ett användarfel: ingen
 * MCP-väg ska nå actionen, och undantaget är sista försvaret.
 */
final class AgentCallerRefusedException extends LogicException {}
