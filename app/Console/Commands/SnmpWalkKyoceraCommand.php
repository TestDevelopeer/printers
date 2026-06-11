<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SnmpWalkKyoceraCommand extends Command
{
    protected $signature = 'printers:snmp-walk-kyocera
                            {ip : IP-адрес принтера}
                            {--community= : SNMP community (по умолчанию из config)}
                            {--timeout=8 : Таймаут walk в секундах}';

    protected $description = 'SNMP walk по enterprise-ветке Kyocera (1.3.6.1.4.1.1347) с поиском серийников расходников.';

    private const KYOCERA_ENTERPRISE_OID = '1.3.6.1.4.1.1347';

    /** @var list<string> */
    private const KNOWN_SUPPLY_DESCRIPTIONS = [
        'TK-5240C',
        'TK-5240M',
        'TK-5240Y',
        'TK-5240K',
        'Waste Toner Box',
    ];

    public function handle(): int
    {
        if (! extension_loaded('snmp')) {
            $this->error('PHP SNMP extension не установлена.');

            return self::FAILURE;
        }

        $ip = (string) $this->argument('ip');
        $community = (string) ($this->option('community')
            ?: config('printers.default_snmp_community', 'public'));
        $timeoutSeconds = max(1, (int) $this->option('timeout'));
        $timeoutMicroseconds = $timeoutSeconds * 1_000_000;

        snmp_set_quick_print(true);
        snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);

        $this->info("SNMP walk {$ip} / ".self::KYOCERA_ENTERPRISE_OID." (community: {$community})");

        $walk = @snmp2_real_walk($ip, $community, self::KYOCERA_ENTERPRISE_OID, $timeoutMicroseconds, 0);

        if ($walk === false) {
            $this->error('Enterprise walk не удался (таймаут или OID недоступен).');

            return self::FAILURE;
        }

        $this->line('Получено OID: '.count($walk));

        $supplyHits = $this->findSupplyDescriptionHits($walk);
        $serialHits = $this->findKyoceraSerialHits($walk);
        $branches = $this->countBranches($walk);

        $this->newLine();
        $this->components->info('Совпадения с описаниями расходников (TK-5240*, Waste Toner Box)');
        if ($supplyHits === []) {
            $this->line('  (нет — расходники в enterprise-ветке не описаны текстом)');
        } else {
            foreach ($supplyHits as $oid => $value) {
                $this->line("  {$oid} => {$value}");
            }
        }

        $this->newLine();
        $this->components->info('Строки формата серийника Kyocera (XX + цифры)');
        if ($serialHits === []) {
            $this->line('  (нет)');
        } else {
            foreach ($serialHits as $oid => $value) {
                $label = str_contains($oid, '.43.5.1.1.28.')
                    ? ' [серийник принтера]'
                    : '';
                $this->line("  {$oid} => {$value}{$label}");
            }
        }

        $this->newLine();
        $this->components->info('Распределение по подветкам 1347.X');
        foreach ($branches as $branch => $count) {
            $this->line("  1347.{$branch}: {$count} OID");
        }

        $payload = [
            'ip_address' => $ip,
            'base_oid' => self::KYOCERA_ENTERPRISE_OID,
            'community' => $community,
            'collected_at' => now()->toIso8601String(),
            'oid_count' => count($walk),
            'supply_hits' => $supplyHits,
            'serial_hits' => $serialHits,
            'branches' => $branches,
            'walk' => $walk,
            'conclusion' => $this->buildConclusion($supplyHits, $serialHits),
        ];

        $directory = storage_path('app/printer-poll-dumps/'.now()->format('Y-m-d'));
        File::ensureDirectoryExists($directory);

        $path = $directory."/{$ip}-kyocera-enterprise.json";
        File::put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );

        $this->newLine();
        $this->info("Полный dump: {$path}");
        $this->newLine();
        $this->line($payload['conclusion']);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $walk
     * @return array<string, string>
     */
    private function findSupplyDescriptionHits(array $walk): array
    {
        $hits = [];

        foreach ($walk as $oid => $value) {
            $value = $this->normalizeSnmpValue($value);

            foreach (self::KNOWN_SUPPLY_DESCRIPTIONS as $description) {
                if (strcasecmp($value, $description) === 0 || str_contains($value, $description)) {
                    $hits[$oid] = $value;
                }
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, string>  $walk
     * @return array<string, string>
     */
    private function findKyoceraSerialHits(array $walk): array
    {
        $hits = [];

        foreach ($walk as $oid => $value) {
            $value = $this->normalizeSnmpValue($value);

            if (preg_match('/^[A-Z]{2}[0-9]{8,}$/', $value)) {
                $hits[$oid] = $value;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, string>  $walk
     * @return array<string, int>
     */
    private function countBranches(array $walk): array
    {
        $branches = [];

        foreach (array_keys($walk) as $oid) {
            if (preg_match('/^\.?1\.3\.6\.1\.4\.1\.1347\.(\d+)\./', $oid, $matches)) {
                $branches[$matches[1]] = ($branches[$matches[1]] ?? 0) + 1;
            }
        }

        ksort($branches, SORT_NUMERIC);

        return $branches;
    }

    /**
     * @param  array<string, string>  $supplyHits
     * @param  array<string, string>  $serialHits
     */
    private function buildConclusion(array $supplyHits, array $serialHits): string
    {
        $printerSerials = array_filter(
            $serialHits,
            static fn (string $value, string $oid): bool => str_contains($oid, '.43.5.1.1.28.'),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($supplyHits === [] && count($printerSerials) === count($serialHits)) {
            return 'Вывод: в enterprise-ветке Kyocera нет текстовых описаний расходников и нет отдельных серийников картриджей. '
                .'Найден только серийник принтера (OID .1347.43.5.1.1.28.1). '
                .'Идентификация расходников возможна только через стандартный Printer MIB (1.3.6.1.2.1.43.11) по типу/слоту.';
        }

        return 'Вывод: проверьте supply_hits и serial_hits в сохранённом JSON — возможны vendor-специфичные поля на этой модели.';
    }

    private function normalizeSnmpValue(mixed $value): string
    {
        return trim((string) $value, "\" \t\n\r\0\x0B");
    }
}
