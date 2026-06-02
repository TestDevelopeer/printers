<?php

namespace App\Services\Printers\Data;

final readonly class DiscoveredPrinterData
{
    /**
     * @param  array<int, array<string, mixed>>  $tonerSupplies
     */
    public function __construct(
        public string $ipAddress,
        public ?string $discoveredName = null,
        public ?string $hostname = null,
        public ?string $macAddress = null,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?string $serialNumber = null,
        public ?string $location = null,
        public ?string $description = null,
        public array $tonerSupplies = [],
        public string $snmpCommunity = 'public',
        public string $snmpVersion = '2c',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPrinterAttributes(): array
    {
        return [
            'discovered_name' => $this->discoveredName,
            'ip_address' => $this->ipAddress,
            'hostname' => $this->hostname,
            'mac_address' => $this->macAddress,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serialNumber,
            'location' => $this->location,
            'snmp_community' => $this->snmpCommunity,
            'snmp_version' => $this->snmpVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'discovered_name' => $this->discoveredName,
            'hostname' => $this->hostname,
            'mac_address' => $this->macAddress,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serialNumber,
            'location' => $this->location,
            'description' => $this->description,
            'toner_supplies' => $this->tonerSupplies,
            'snmp_community' => $this->snmpCommunity,
            'snmp_version' => $this->snmpVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ipAddress: (string) $data['ip_address'],
            discoveredName: $data['discovered_name'] ?? null,
            hostname: $data['hostname'] ?? null,
            macAddress: $data['mac_address'] ?? null,
            manufacturer: $data['manufacturer'] ?? null,
            model: $data['model'] ?? null,
            serialNumber: $data['serial_number'] ?? null,
            location: $data['location'] ?? null,
            description: $data['description'] ?? null,
            tonerSupplies: $data['toner_supplies'] ?? [],
            snmpCommunity: $data['snmp_community'] ?? config('printers.default_snmp_community', 'public'),
            snmpVersion: $data['snmp_version'] ?? config('printers.default_snmp_version', '2c'),
        );
    }
}
