<?php

namespace App\Services\Printers\Data;

use Illuminate\Support\Carbon;

final class SnmpDumpBuilder
{
    /** @var array<string, array{value: ?string, success: bool}> */
    private array $gets = [];

    /** @var array<string, array<string, string>> */
    private array $walks = [];

    public function recordGet(string $oid, ?string $value, bool $success): void
    {
        $this->gets[$oid] = [
            'value' => $value,
            'success' => $success,
        ];
    }

    /**
     * @param  array<string, string>  $results
     */
    public function recordWalk(string $baseOid, array $results): void
    {
        $this->walks[$baseOid] = $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $ipAddress, string $community, int $timeoutMs): array
    {
        return [
            'ip_address' => $ipAddress,
            'community' => $community,
            'timeout_ms' => $timeoutMs,
            'collected_at' => Carbon::now()->toIso8601String(),
            'gets' => $this->gets,
            'walks' => $this->walks,
        ];
    }

    public function hasAnyData(): bool
    {
        return $this->gets !== [] || $this->walks !== [];
    }

    public function hasFailedRequests(): bool
    {
        foreach ($this->gets as $entry) {
            if (! $entry['success']) {
                return true;
            }
        }

        return false;
    }

    public function hasEmptyWalksWithData(): bool
    {
        foreach ($this->walks as $results) {
            if ($results === []) {
                return true;
            }
        }

        return false;
    }
}
