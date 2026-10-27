<?php

declare(strict_types=1);

/**
 * The PHPStan rule (extension.neon): "codegen emits a PHPStan extension from
 * the metadata, so a misspelled column fails static analysis". Run as
 * PHPStan runs, over a fixture of calls, so what is tested is the rule as
 * an application includes it (ADR 0057).
 */

namespace Tinvar\Tests\Orm\QueryPhpStan;

use Composer\InstalledVersions;

/** @return array{list<int>, list<string>, string} the lines reported, their messages, and what PHPStan said */
function analyse(): array
{
    $fixtures = __DIR__ . '/Fixtures/phpstan';
    $scratch = sys_get_temp_dir() . '/tinvar-phpstan-' . bin2hex(random_bytes(4));
    mkdir($scratch, 0700);
    $config = "{$scratch}/phpstan.neon";
    file_put_contents($config, implode("\n", [
        'includes:',
        '    - ' . \dirname(__DIR__, 2) . '/extension.neon',
        'parameters:',
        '    level: 0',
        '    paths:',
        "        - {$fixtures}/calls.php",
        '    bootstrapFiles:',
        "        - {$fixtures}/models.php",
        "    tmpDir: {$scratch}/cache",
        '    tinvar:',
        '        models: [Tinvar\Tests\Orm\PHPStanFixture\Crate, Tinvar\Tests\Orm\PHPStanFixture\Pallet]',
        '        tenantTables: [crate_events]',
        '',
    ]));

    $binary = InstalledVersions::getInstallPath('phpstan/phpstan') . '/phpstan';
    $command = [\PHP_BINARY, $binary, 'analyse', '-c', $config, '--error-format=json', '--no-progress', '--memory-limit=1G'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    \assert(\is_resource($process));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    exec('rm -rf ' . escapeshellarg($scratch));

    $report = json_decode($out, true);
    if (!\is_array($report)) {
        throw new \RuntimeException("PHPStan did not report: {$out}{$err}");
    }

    $lines = [];
    $messages = [];
    foreach ($report['files'] ?? [] as $file) {
        foreach ($file['messages'] as $message) {
            $lines[] = $message['line'];
            $messages[] = $message['message'];
        }
    }
    sort($lines);

    return [$lines, $messages, $out . $err];
}

it('reports every misspelt column, relation and raw condition, and nothing else', function () {
    [$lines, $messages, $said] = analyse();

    $refused = [];
    foreach (file(__DIR__ . '/Fixtures/phpstan/calls.php') ?: [] as $i => $line) {
        if (str_ends_with(rtrim($line), '// refused')) {
            $refused[] = $i + 1;
        }
    }

    expect($lines)->toBe($refused, $said)
        ->and($messages[0])->toContain('Query<Tinvar\Tests\Orm\PHPStanFixture\Crate>::where()')
        ->and($messages[0])->toContain("has no column 'lable'")
        ->and(implode("\n", $messages))->toContain('Connection::select() sends raw SQL on the tenant table crates: nothing adds the tenant predicate')
        ->and(implode("\n", $messages))->toContain('Connection::execute() sends raw SQL on the tenant table crate_events')
        ->and(implode("\n", $messages))->toContain('Connection::select() is sent SQL PHPStan cannot read');
});
