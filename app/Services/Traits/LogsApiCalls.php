<?php

namespace App\Services\Traits;

use App\Models\IntegrationLog;

trait LogsApiCalls
{
    /**
     * Wykonaj zapytanie HTTP z logowaniem do bazy
     */
    protected function loggedRequest(
        string $service,
        string $method,
        string $endpoint,
        callable $callback,
        ?array $requestData = null,
    ): mixed {
        $start = microtime(true);

        try {
            $result = $callback();
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            IntegrationLog::logCall(
                service: $service,
                method: $method,
                endpoint: $endpoint,
                requestData: $requestData,
                responseStatus: 200,
                responseSummary: is_array($result) ? (count($result) . ' items') : 'OK',
                durationMs: $durationMs,
                status: 'success',
            );

            return $result;
        } catch (\Exception $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);

            IntegrationLog::logCall(
                service: $service,
                method: $method,
                endpoint: $endpoint,
                requestData: $requestData,
                responseStatus: null,
                responseSummary: null,
                durationMs: $durationMs,
                status: 'error',
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }
}
