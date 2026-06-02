<?php

namespace App\Services\Printers;

use App\Enums\TonerColor;
use App\Services\Printers\Data\DiscoveredPrinterData;
use RuntimeException;

class PrinterSnmpService
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const SYS_LOCATION = '1.3.6.1.2.1.1.6.0';
    private const PRINTER_NAME = '1.3.6.1.2.1.43.5.1.1.16.1';
    private const SERIAL_NUMBER = '1.3.6.1.2.1.43.5.1.1.17.1';
    private const SUPPLIES_DESCRIPTION = '1.3.6.1.2.1.43.11.1.1.6.1';
    private const SUPPLIES_LEVEL = '1.3.6.1.2.1.43.11.1.1.9.1';
    private const SUPPLIES_CAPACITY = '1.3.6.1.2.1.43.11.1.1.8.1';

    public function discover(
        string $ipAddress,
        ?string $community = null,
        ?int $timeoutMs = null,
    ): ?DiscoveredPrinterData
    {
        $community ??= config('printers.default_snmp_community', 'public');
        $timeoutMs ??= config('printers.poll_timeout', 1000);

        $this->configureSnmp();

        $description = $this->snmpGet($ipAddress, self::SYS_DESCR, $community, $timeoutMs);
        $hostname = $this->snmpGet($ipAddress, self::SYS_NAME, $community, $timeoutMs);
        $location = $this->snmpGet($ipAddress, self::SYS_LOCATION, $community, $timeoutMs);
        $printerName = $this->snmpGet($ipAddress, self::PRINTER_NAME, $community, $timeoutMs);
        $serialNumber = $this->snmpGet($ipAddress, self::SERIAL_NUMBER, $community, $timeoutMs);

        $tonerSupplies = $this->readTonerSupplies($ipAddress, $community, $timeoutMs);

        if (! $printerName && empty($tonerSupplies) && ! $this->looksLikePrinter($description)) {
            return null;
        }

        ['manufacturer' => $manufacturer, 'model' => $model] = $this->extractManufacturerAndModel(
            $description,
            $printerName,
        );

        return new DiscoveredPrinterData(
            ipAddress: $ipAddress,
            discoveredName: $printerName ?: $hostname,
            hostname: $hostname,
            macAddress: null,
            manufacturer: $manufacturer,
            model: $model,
            serialNumber: $serialNumber,
            location: $location,
            description: $description,
            tonerSupplies: $tonerSupplies,
            snmpCommunity: $community,
            snmpVersion: config('printers.default_snmp_version', '2c'),
        );
    }

    private function configureSnmp(): void
    {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
    }

    private function snmpGet(string $ipAddress, string $oid, string $community, int $timeoutMs): ?string
    {
        $result = @snmp2_get($ipAddress, $community, $oid, $timeoutMs * 1000, 0);

        if ($result === false) {
            return null;
        }

        $value = trim((string) $result, "\" \t\n\r\0\x0B");

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function snmpWalk(string $ipAddress, string $oid, string $community, int $timeoutMs): array
    {
        $result = @snmp2_real_walk($ipAddress, $community, $oid, $timeoutMs * 1000, 0);

        if ($result === false) {
            return [];
        }

        return array_map(
            static fn (mixed $value): string => trim((string) $value, "\" \t\n\r\0\x0B"),
            $result,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTonerSupplies(string $ipAddress, string $community, int $timeoutMs): array
    {
        $descriptions = $this->snmpWalk($ipAddress, self::SUPPLIES_DESCRIPTION, $community, $timeoutMs);
        $levels = $this->snmpWalk($ipAddress, self::SUPPLIES_LEVEL, $community, $timeoutMs);
        $capacities = $this->snmpWalk($ipAddress, self::SUPPLIES_CAPACITY, $community, $timeoutMs);

        $supplies = [];

        foreach ($descriptions as $oid => $description) {
            $suffix = $this->oidSuffix($oid);
            $level = $this->findBySuffix($levels, $suffix);
            $capacity = $this->findBySuffix($capacities, $suffix);
            $levelInt = is_numeric($level) ? (int) $level : null;
            $capacityInt = is_numeric($capacity) ? (int) $capacity : null;
            $percentage = $this->calculatePercentage($levelInt, $capacityInt);
            $known = $percentage !== null && $levelInt !== null && $capacityInt !== null && $levelInt >= 0;

            $supplies[] = [
                'color' => $this->guessColor($description)->value,
                'snmp_description' => $description,
                'level' => $levelInt,
                'max_capacity' => $capacityInt,
                'percentage' => $percentage,
                'unit' => 'percent',
                'is_known' => $known,
                'raw_value' => [
                    'description' => $description,
                    'level' => $level,
                    'max_capacity' => $capacity,
                ],
            ];
        }

        return $supplies;
    }

    private function oidSuffix(string $oid): string
    {
        $parts = explode('.', $oid);

        return end($parts) ?: $oid;
    }

    /**
     * @param  array<string, string>  $walk
     */
    private function findBySuffix(array $walk, string $suffix): ?string
    {
        foreach ($walk as $oid => $value) {
            if ($this->oidSuffix($oid) === $suffix) {
                return $value;
            }
        }

        return null;
    }

    private function calculatePercentage(?int $level, ?int $capacity): ?int
    {
        if ($level === null || $capacity === null || $capacity <= 0 || $level < 0) {
            return null;
        }

        return max(0, min(100, (int) round(($level / $capacity) * 100)));
    }

    private function looksLikePrinter(?string $description): bool
    {
        if ($description === null) {
            return false;
        }

        return str_contains(strtolower($description), 'printer');
    }

    /**
     * @return array{manufacturer: ?string, model: ?string}
     */
    private function extractManufacturerAndModel(?string $description, ?string $printerName): array
    {
        $source = trim((string) ($printerName ?: $description));

        if ($source === '') {
            return ['manufacturer' => null, 'model' => null];
        }

        $parts = preg_split('/\s+/', $source) ?: [];
        $manufacturer = $parts[0] ?? null;
        $model = trim(preg_replace('/^' . preg_quote((string) $manufacturer, '/') . '\s*/', '', $source) ?? '');

        return [
            'manufacturer' => $manufacturer,
            'model' => $model !== '' ? $model : null,
        ];
    }

    private function guessColor(?string $description): TonerColor
    {
        $description = strtolower((string) $description);

        return match (true) {
            str_contains($description, 'black') => TonerColor::Black,
            str_contains($description, 'cyan') => TonerColor::Cyan,
            str_contains($description, 'magenta') => TonerColor::Magenta,
            str_contains($description, 'yellow') => TonerColor::Yellow,
            str_contains($description, 'waste') => TonerColor::Waste,
            $description !== '' => TonerColor::Other,
            default => TonerColor::Unknown,
        };
    }
}
}
