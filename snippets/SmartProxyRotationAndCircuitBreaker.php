<?php

namespace App\Services\Extraction\Snippets;

use App\Models\Proxy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GovTech Pipeline - Smart Proxy Rotation (LRU + Quarantine) & Dual Circuit Breakers
 * Reference implementation from app/Models/Proxy.php and PncpConsultaCircuitBreaker.php
 */
class SmartProxyRotationAndCircuitBreaker
{
    /**
     * Executes an HTTP request against government endpoints protected by Circuit Breakers & Proxy Rotation.
     */
    public function executeRequest(string $url, array $params = []): ?array
    {
        // 1. Guardrail: Check Circuit Breaker status
        if ($this->isCircuitOpen('pncp_consulta')) {
            Log::warning("Circuit Breaker is OPEN. Halting request to prevent cascade failure.");
            return null;
        }

        // 2. Select the least recently used (LRU) active proxy
        $proxy = Proxy::query()
            ->where('is_active', true)
            ->orderBy('last_used_at', 'asc')
            ->first();

        $proxyUrl = $proxy ? "http://{$proxy->username}:{$proxy->password}@{$proxy->host}:{$proxy->port}" : null;

        $startTime = microtime(true);
        $request = Http::timeout(10);

        if ($proxyUrl) {
            $request = $request->withOptions(['proxy' => $proxyUrl]);
        }

        try {
            $response = $request->get($url, $params);
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                // Register success telemetries and reset failure counters
                if ($proxy) {
                    $proxy->update([
                        'success_count' => $proxy->success_count + 1,
                        'fail_count' => 0,
                        'last_used_at' => now(),
                        'latency_ms' => $latencyMs,
                    ]);
                }
                return $response->json();
            }

            // Upstream HTTP 5xx / 429 Error
            $this->handleFailure($proxy, "HTTP {$response->status()}: {$response->body()}");
            return null;

        } catch (\Exception $e) {
            $this->handleFailure($proxy, $e->getMessage());
            return null;
        }
    }

    /**
     * Handles proxy failure counting, quarantine, and circuit breaker tripping.
     */
    protected function handleFailure(?Proxy $proxy, string $error): void
    {
        if ($proxy) {
            $newFailCount = $proxy->fail_count + 1;
            $proxy->update([
                'fail_count' => $newFailCount,
                'last_used_at' => now(),
                'last_error' => $error,
                // Quarantine node if failures exceed threshold
                'is_active' => $newFailCount < 5,
            ]);

            if ($newFailCount >= 5) {
                Log::error("Proxy [ID: {$proxy->id}] QUARANTINED due to 5 consecutive failures.");
            }
        }

        // Increment circuit breaker failure tracking
        $recentFailures = Cache::increment('pncp_consulta_consecutive_fails');
        if ($recentFailures >= 3) {
            $this->tripCircuit('pncp_consulta', $error);
        }
    }

    public function isCircuitOpen(string $service): bool
    {
        return Cache::get("{$service}_status") === 'OPEN';
    }

    public function tripCircuit(string $service, string $reason): void
    {
        Cache::put("{$service}_status", 'OPEN', now()->addMinutes(5));
        Log::warning("Circuit Breaker TRIPPED for [{$service}]. Reason: {$reason}. Pausing for 5 minutes.");
    }
}
