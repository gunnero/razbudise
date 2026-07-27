<?php

namespace App\Console\Commands;

use App\Services\Wordpress\WordpressPostRepairMaterializationService;
use Illuminate\Console\Command;
use RuntimeException;

class WordpressRebindPostRepairMaterializationCommand extends Command
{
    protected $signature = 'wordpress:rebind-post-repair-materialization
        {evidence : Root-owned private repair evidence JSON under storage/app}
        {--expected-sha256= : Exact SHA-256 of the repair evidence JSON}
        {--expected-repair-chain-sha256= : Exact SHA-256 of the authorized repair-chain artifact}
        {--expected-reconciliation-sha256= : Exact frozen reconciliation manifest SHA-256}
        {--expected-unavailable-media-sha256= : Exact approved unavailable-media manifest SHA-256}
        {--apply : Persist materialization signatures without changing article content}
        {--json : Print machine-readable audit output}';

    protected $description = 'Bind authorized post-repair article bodies to frozen reconciliation provenance.';

    public function handle(WordpressPostRepairMaterializationService $service): int
    {
        try {
            $path = $this->privateEvidencePath((string) $this->argument('evidence'));
            $expected = strtolower(trim((string) $this->option('expected-sha256')));
            $actual = hash_file('sha256', $path);
            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
                || ! is_string($actual)
                || ! hash_equals($expected, $actual)) {
                throw new RuntimeException('repair_evidence_sha256_mismatch');
            }
            $evidence = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($evidence)) {
                throw new RuntimeException('repair_evidence_invalid');
            }
            foreach ([
                'repair_chain_sha256' => 'expected-repair-chain-sha256',
                'reconciliation_sha256' => 'expected-reconciliation-sha256',
                'unavailable_media_sha256' => 'expected-unavailable-media-sha256',
            ] as $key => $option) {
                $expectedEvidenceHash = strtolower(trim((string) $this->option($option)));
                if (preg_match('/^[a-f0-9]{64}$/', $expectedEvidenceHash) !== 1
                    || ! hash_equals($expectedEvidenceHash, (string) ($evidence[$key] ?? ''))) {
                    throw new RuntimeException("{$key}_mismatch");
                }
            }
            $result = $service->run($evidence, (bool) $this->option('apply'));
            $result['evidence']['sha256'] = $actual;
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $result = ['valid' => false, 'error' => $exception->getMessage(), 'body_writes' => 0];
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function privateEvidencePath(string $path): string
    {
        $real = realpath($path);
        $root = realpath(storage_path('app'));
        if (! is_string($real) || ! is_string($root) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)
            || is_link($path) || ! is_file($real) || ! is_readable($real)) {
            throw new RuntimeException('repair_evidence_private_file_invalid');
        }
        $permissions = fileperms($real);
        $directoryPermissions = fileperms(dirname($real));
        if (! is_int($permissions) || ! is_int($directoryPermissions)
            || (($permissions & 0777) & 0137) !== 0
            || (($directoryPermissions & 0777) & 0027) !== 0
            || (($directoryPermissions & 0777) & 0222) !== 0
            || fileowner($real) !== fileowner(dirname($real))) {
            throw new RuntimeException('repair_evidence_permissions_invalid');
        }

        return $real;
    }
}
