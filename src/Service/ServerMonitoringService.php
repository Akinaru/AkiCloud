<?php

namespace App\Service;

class ServerMonitoringService
{
    public function getServerStats(): array
    {
        return [
            'cpu' => $this->getCPUUsage(),
            'ram_used' => $this->getRAMUsed(),
            'ram_total' => $this->getRAMTotal(),
            'disk_used' => $this->getDiskUsed(),
            'disk_total' => $this->getDiskTotal(),
            'uptime' => $this->getUptime(),
        ];
    }

    private function getCPUUsage(): float
    {
        $load = sys_getloadavg();
        $coreCount = $this->getCoreCount();
        
        // On estime le pourcentage par rapport au nombre de coeurs
        $usage = ($load[0] / $coreCount) * 100;
        
        return round(min($usage, 100), 1);
    }

    private function getCoreCount(): int
    {
        if (is_file('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]) ?: 1;
        }
        return 1;
    }

    private function getRAMStats(): array
    {
        $meminfo = [];
        if (is_file('/proc/meminfo')) {
            $lines = file('/proc/meminfo');
            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                    $meminfo[$matches[1]] = (int) $matches[2];
                }
            }
        }

        $total = ($meminfo['MemTotal'] ?? 0) / 1024 / 1024; // Go
        $available = ($meminfo['MemAvailable'] ?? 0) / 1024 / 1024; // Go
        $used = $total - $available;

        return [
            'total' => round($total, 1),
            'used' => round($used, 1),
        ];
    }

    private function getRAMTotal(): float
    {
        return $this->getRAMStats()['total'];
    }

    private function getRAMUsed(): float
    {
        return $this->getRAMStats()['used'];
    }

    private function getDiskTotal(): float
    {
        $total = disk_total_space('/') / 1024 / 1024 / 1024; // Go
        return round($total, 1);
    }

    private function getDiskUsed(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = ($total - $free) / 1024 / 1024 / 1024; // Go
        return round($used, 1);
    }

    private function getUptime(): string
    {
        if (is_file('/proc/uptime')) {
            $uptimeSeconds = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
            
            $days = floor($uptimeSeconds / 86400);
            $hours = floor(($uptimeSeconds % 86400) / 3600);
            $minutes = floor(($uptimeSeconds % 3600) / 60);

            if ($days > 0) {
                return sprintf('%dj %dh', $days, $hours);
            }
            if ($hours > 0) {
                return sprintf('%dh %dm', $hours, $minutes);
            }
            return sprintf('%dm', $minutes);
        }

        return 'N/A';
    }
}
