<?php

namespace App\Services\Printers;

use App\Enums\TonerColor;
use App\Services\Printers\Data\DiscoveredPrinterData;
use App\Services\Printers\Data\SnmpDiscoveryResult;
use App\Services\Printers\Data\SnmpDumpBuilder;

class PrinterSnmpService
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const SYS_LOCATION = '1.3.6.1.2.1.1.6.0';
    private const PRINTER_NAME = '1.3.6.1.2.1.43.5.1.1.16.1';
    private const SERIAL_NUMBER = '1.3.6.1.2.1.43.5.1.1.17.1';
    private const SUPPLIES_MARKER_INDEX = '1.3.6.1.2.1.43.11.1.1.2.1';
    private const SUPPLIES_COLORANT_INDEX = '1.3.6.1.2.1.43.11.1.1.3.1';
    private const SUPPLIES_CLASS = '1.3.6.1.2.1.43.11.1.1.4.1';
    private const SUPPLIES_TYPE = '1.3.6.1.2.1.43.11.1.1.5.1';
    private const SUPPLIES_DESCRIPTION = '1.3.6.1.2.1.43.11.1.1.6.1';
    private const SUPPLIES_UNIT = '1.3.6.1.2.1.43.11.1.1.7.1';
    private const SUPPLIES_LEVEL = '1.3.6.1.2.1.43.11.1.1.9.1';
    private const SUPPLIES_CAPACITY = '1.3.6.1.2.1.43.11.1.1.8.1';
    private const COLORANT_VALUE = '1.3.6.1.2.1.43.12.1.1.4.1';

    /**
     * @return array{description: ?string, hostname: ?string, printer_name: ?string}|null
     */
    public function probe(
        string $ipAddress,
        ?string $community = null,
        ?int $timeoutMs = null,
    ): ?array
    {
        $community ??= config('printers.default_snmp_community', 'public');
        $timeoutMs ??= config('printers.poll_timeout', 1000);

        $this->configureSnmp();

        $description = $this->snmpGet($ipAddress, self::SYS_DESCR, $community, $timeoutMs);
        $hostname = $this->snmpGet($ipAddress, self::SYS_NAME, $community, $timeoutMs);
        $printerName = $this->snmpGet($ipAddress, self::PRINTER_NAME, $community, $timeoutMs);

        if (! $printerName && ! $this->looksLikePrinter($description)) {
            return null;
        }

        return [
            'description' => $description,
            'hostname' => $hostname,
            'printer_name' => $printerName,
        ];
    }

    public function discover(
        string $ipAddress,
        ?string $community = null,
        ?int $timeoutMs = null,
        ?array $probe = null,
    ): ?DiscoveredPrinterData
    {
        return $this->discoverWithDump($ipAddress, $community, $timeoutMs, $probe)->discovered;
    }

    public function discoverWithDump(
        string $ipAddress,
        ?string $community = null,
        ?int $timeoutMs = null,
        ?array $probe = null,
    ): SnmpDiscoveryResult
    {
        $community ??= config('printers.default_snmp_community', 'public');
        $timeoutMs ??= config('printers.poll_timeout', 1000);

        $this->configureSnmp();

        $dumpBuilder = new SnmpDumpBuilder();

        $description = $probe['description'] ?? $this->snmpGet($ipAddress, self::SYS_DESCR, $community, $timeoutMs, $dumpBuilder);
        $hostname = $probe['hostname'] ?? $this->snmpGet($ipAddress, self::SYS_NAME, $community, $timeoutMs, $dumpBuilder);
        $location = $this->snmpGet($ipAddress, self::SYS_LOCATION, $community, $timeoutMs, $dumpBuilder);
        $printerName = $probe['printer_name'] ?? $this->snmpGet($ipAddress, self::PRINTER_NAME, $community, $timeoutMs, $dumpBuilder);
        $serialNumber = $this->snmpGet($ipAddress, self::SERIAL_NUMBER, $community, $timeoutMs, $dumpBuilder);

        $tonerSupplies = $this->readTonerSupplies($ipAddress, $community, $timeoutMs, $dumpBuilder);

        $dump = $dumpBuilder->toArray($ipAddress, $community, $timeoutMs);
        $isPartial = $dumpBuilder->hasFailedRequests()
            || $dumpBuilder->hasEmptyWalksWithData()
            || ($dumpBuilder->hasAnyData() && $printerName === null && empty($tonerSupplies));

        if (! $printerName && empty($tonerSupplies) && ! $this->looksLikePrinter($description)) {
            return new SnmpDiscoveryResult(
                discovered: null,
                dump: $dump,
                isPartialResponse: $isPartial || $dumpBuilder->hasAnyData(),
                failureReason: $dumpBuilder->hasAnyData()
                    ? 'Устройство ответило по SNMP, но не определилось как принтер.'
                    : 'SNMP-ответ не получен или устройство не отвечает.',
            );
        }

        ['manufacturer' => $manufacturer, 'model' => $model] = $this->extractManufacturerAndModel(
            $description,
            $printerName,
        );

        return new SnmpDiscoveryResult(
            discovered: new DiscoveredPrinterData(
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
            ),
            dump: $dump,
            isPartialResponse: $isPartial,
            failureReason: null,
        );
    }

    private function configureSnmp(): void
    {
        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
    }

    private function snmpGet(
        string $ipAddress,
        string $oid,
        string $community,
        int $timeoutMs,
        ?SnmpDumpBuilder $dumpBuilder = null,
    ): ?string
    {
        $result = @snmp2_get($ipAddress, $community, $oid, $timeoutMs * 1000, 0);

        $value = null;
        $success = $result !== false;

        if ($success) {
            $value = trim((string) $result, "\" \t\n\r\0\x0B");
            $value = $value === '' ? null : $value;
        }

        $dumpBuilder?->recordGet($oid, $value, $success);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function snmpWalk(
        string $ipAddress,
        string $oid,
        string $community,
        int $timeoutMs,
        ?SnmpDumpBuilder $dumpBuilder = null,
    ): array
    {
        $result = @snmp2_real_walk($ipAddress, $community, $oid, $timeoutMs * 1000, 0);

        if ($result === false) {
            $dumpBuilder?->recordWalk($oid, []);

            return [];
        }

        $normalized = array_map(
            static fn (mixed $value): string => trim((string) $value, "\" \t\n\r\0\x0B"),
            $result,
        );

        $dumpBuilder?->recordWalk($oid, $normalized);

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTonerSupplies(
        string $ipAddress,
        string $community,
        int $timeoutMs,
        ?SnmpDumpBuilder $dumpBuilder = null,
    ): array
    {
        $markerIndexes = $this->snmpWalk($ipAddress, self::SUPPLIES_MARKER_INDEX, $community, $timeoutMs, $dumpBuilder);
        $colorantIndexes = $this->snmpWalk($ipAddress, self::SUPPLIES_COLORANT_INDEX, $community, $timeoutMs, $dumpBuilder);
        $classes = $this->snmpWalk($ipAddress, self::SUPPLIES_CLASS, $community, $timeoutMs, $dumpBuilder);
        $types = $this->snmpWalk($ipAddress, self::SUPPLIES_TYPE, $community, $timeoutMs, $dumpBuilder);
        $descriptions = $this->snmpWalk($ipAddress, self::SUPPLIES_DESCRIPTION, $community, $timeoutMs, $dumpBuilder);
        $units = $this->snmpWalk($ipAddress, self::SUPPLIES_UNIT, $community, $timeoutMs, $dumpBuilder);
        $levels = $this->snmpWalk($ipAddress, self::SUPPLIES_LEVEL, $community, $timeoutMs, $dumpBuilder);
        $capacities = $this->snmpWalk($ipAddress, self::SUPPLIES_CAPACITY, $community, $timeoutMs, $dumpBuilder);
        $colorantValues = $this->snmpWalk($ipAddress, self::COLORANT_VALUE, $community, $timeoutMs, $dumpBuilder);

        $supplies = [];

        foreach ($descriptions as $oid => $description) {
            $suffix = $this->oidSuffix($oid);
            $markerIndex = $this->findBySuffix($markerIndexes, $suffix);
            $colorantIndex = $this->findBySuffix($colorantIndexes, $suffix);
            $class = $this->findBySuffix($classes, $suffix);
            $type = $this->findBySuffix($types, $suffix);
            $unit = $this->findBySuffix($units, $suffix);
            $level = $this->findBySuffix($levels, $suffix);
            $capacity = $this->findBySuffix($capacities, $suffix);
            $colorantValue = $this->findColorantValue($colorantValues, $colorantIndex);
            $levelInt = is_numeric($level) ? (int) $level : null;
            $capacityInt = is_numeric($capacity) ? (int) $capacity : null;
            $percentage = $this->calculatePercentage($levelInt, $capacityInt);
            $known = $percentage !== null && $levelInt !== null && $capacityInt !== null && $levelInt >= 0;
            $detectedColor = $this->detectColor($colorantValue ?: $description);

            $supplies[] = [
                'slot_key' => $suffix,
                'color' => $detectedColor->value,
                'snmp_description' => $description,
                'level' => $levelInt,
                'max_capacity' => $capacityInt,
                'percentage' => $percentage,
                'unit' => 'percent',
                'is_known' => $known,
                'raw_value' => [
                    'slot_key' => $suffix,
                    'marker_index' => $markerIndex,
                    'colorant_index' => $colorantIndex,
                    'colorant_value' => $colorantValue,
                    'supply_class' => $class,
                    'supply_type' => $type,
                    'supply_unit' => $unit,
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

    /**
     * @param  array<string, string>  $colorantValues
     */
    private function findColorantValue(array $colorantValues, ?string $colorantIndex): ?string
    {
        if ($colorantIndex === null) {
            return null;
        }

        foreach ($colorantValues as $oid => $value) {
            if ($this->oidSuffix($oid) === $colorantIndex) {
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

    public function detectColor(?string $description): TonerColor
    {
        $description = trim((string) $description);

        if ($description === '') {
            return TonerColor::Unknown;
        }

        $normalized = mb_strtolower($description);

        $keywords = [
            [TonerColor::Waste, ['waste', 'waste toner', 'отработ', 'отработка', 'used toner']],
            [TonerColor::Black, ['black', 'bk', 'k black', 'черный', 'чёрный']],
            [TonerColor::Cyan, ['cyan', 'голуб', 'синий']],
            [TonerColor::Magenta, ['magenta', 'маджента', 'пурпур']],
            [TonerColor::Yellow, ['yellow', 'желт', 'жёлт']],
        ];

        foreach ($keywords as [$color, $variants]) {
            foreach ($variants as $variant) {
                if (str_contains($normalized, $variant)) {
                    return $color;
                }
            }
        }

        if (preg_match('/(?:^|[\s\/\-_])([kcmy])(?:[\s\/\-_]|$)/iu', $description, $matches)) {
            return $this->mapShortColorCode($matches[1]);
        }

        if (preg_match('/[a-z0-9\-]+([kcmy])$/iu', $description, $matches)) {
            return $this->mapShortColorCode($matches[1]);
        }

        return TonerColor::Other;
    }

    private function mapShortColorCode(string $code): TonerColor
    {
        return match (mb_strtolower($code)) {
            'k' => TonerColor::Black,
            'c' => TonerColor::Cyan,
            'm' => TonerColor::Magenta,
            'y' => TonerColor::Yellow,
            default => TonerColor::Other,
        };
    }
}
