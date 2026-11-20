<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use FluxFiles\DiskManager;
use FluxFiles\BucketDoctor;

$green = "\033[32m"; $red = "\033[31m"; $cyan = "\033[36m"; $reset = "\033[0m";
$passed = 0; $failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed, $green, $red, $reset;
    try { $fn(); echo "  {$green}PASS{$reset} {$name}\n"; $passed++; }
    catch (\Throwable $e) { echo "  {$red}FAIL{$reset} {$name}: {$e->getMessage()}\n"; $failed++; }
}
function assertTrue($c, string $m = ''): void { if (!$c) throw new \RuntimeException($m ?: 'expected true'); }
function assertEqual($e, $a, string $m = ''): void { if ($e !== $a) throw new \RuntimeException($m ?: 'expected ' . json_encode($e) . ' got ' . json_encode($a)); }

echo "\n{$cyan}══ FluxFiles Bucket Doctor (local) ══{$reset}\n\n";

test('local disk → writable probe, summary ok, no driver-specific remediation', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-doctor-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);

    $report = (new BucketDoctor($dm))->diagnose('local');

    assertEqual('local', $report['driver'], 'driver is local');
    assertEqual('ok', $report['summary'], 'summary ok');
    $ids = array_column($report['checks'], 'id');
    assertTrue(in_array('write', $ids, true), 'has a write check');
    $write = array_values(array_filter($report['checks'], fn($c) => $c['id'] === 'write'))[0];
    assertEqual('ok', $write['status'], 'write check passed');
    assertEqual([], $report['remediation'], 'no S3 remediation for local');
});

test('report shape is stable (disk, driver, summary, checks[], remediation)', function () {
    $root = sys_get_temp_dir() . '/fluxfiles-doctor-' . uniqid();
    @mkdir($root, 0777, true);
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => $root, 'url' => '/storage']]);

    $report = (new BucketDoctor($dm))->diagnose('local');
    foreach (['disk', 'driver', 'summary', 'checks', 'remediation'] as $k) {
        assertTrue(array_key_exists($k, $report), "report has {$k}");
    }
    foreach ($report['checks'] as $c) {
        foreach (['id', 'status', 'message'] as $k) { assertTrue(array_key_exists($k, $c), "check has {$k}"); }
        assertTrue(in_array($c['status'], ['ok', 'warn', 'fail', 'info'], true), 'valid status');
    }
});

test('remediation() includes an AbortIncompleteMultipartUpload lifecycle snippet', function () {
    $dm = new DiskManager(['local' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]);
    $doctor = new BucketDoctor($dm);
    $m = new \ReflectionMethod($doctor, 'remediation');
    $remediation = $m->invoke($doctor, 'my-bucket', 'https://app.example');

    assertTrue(isset($remediation['lifecycle']), 'remediation has a lifecycle key');
    $rule = json_decode($remediation['lifecycle'], true)['Rules'][0] ?? [];
    assertEqual('Enabled', $rule['Status'] ?? null, 'rule is enabled');
    assertTrue(isset($rule['AbortIncompleteMultipartUpload']['DaysAfterInitiation']), 'rule aborts incomplete multipart uploads');
});

// ── Time bounds (S3 via a fake handler, SFTP via the doctor provider) ──────────

/** A DiskManager whose S3 client is wired to $handler; $handler sees every command. */
function fakeS3Doctor(callable $handler): array
{
    $dm = new DiskManager(['s3' => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1']]);
    $client = new \Aws\S3\S3Client([
        'version' => 'latest', 'region' => 'us-east-1',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => $handler,
    ]);
    $prop = new \ReflectionProperty($dm, 's3Clients');
    $prop->setAccessible(true);
    $prop->setValue($dm, ['s3' => $client]);
    return [$dm, $client];
}

/** @return \Aws\Result|\GuzzleHttp\Promise\PromiseInterface */
function okResult(\Aws\CommandInterface $cmd)
{
    $r = ['Body' => \GuzzleHttp\Psr7\Utils::streamFor('')];
    if ($cmd->getName() === 'GetObject') {
        $r['Body'] = \GuzzleHttp\Psr7\Utils::streamFor($GLOBALS['probeBody'] ?? '');
    }
    if ($cmd->getName() === 'CreateMultipartUpload') { $r['UploadId'] = 'u1'; }
    return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result($r));
}

test('every S3 command the doctor issues carries @http bounds and @retries=0', function () {
    $seen = [];
    $GLOBALS['probeBody'] = '';
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) use (&$seen) {
        $seen[] = [$cmd->getName(), $cmd['@http'] ?? null, $cmd['@retries'] ?? null];
        if ($cmd->getName() === 'PutObject') { $GLOBALS['probeBody'] = (string) $cmd['Body']; }
        return okResult($cmd);
    });
    $report = (new BucketDoctor($dm, new class implements \FluxFiles\HttpTransportInterface {
        public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
        { return ['status' => 200, 'headers' => [], 'body' => $GLOBALS['probeBody']]; }
    }))->diagnose('s3');

    $names = array_column($seen, 0);
    foreach (['ListObjectsV2', 'PutObject', 'GetObject', 'CreateMultipartUpload', 'AbortMultipartUpload',
              'GetBucketCors', 'GetBucketLifecycleConfiguration', 'GetBucketVersioning', 'DeleteObject'] as $n) {
        assertTrue(in_array($n, $names, true), "{$n} was issued");
    }
    foreach ($seen as [$name, $http, $retries]) {
        assertEqual(['connect_timeout' => 5, 'timeout' => 15], $http, "{$name} @http");
        assertEqual(0, $retries, "{$name} @retries");
    }
    assertEqual('ok', array_values(array_filter($report['checks'], fn($c) => $c['id'] === 'delete'))[0]['status']);
});

test('a connection error on the first check skips the rest (no further S3 calls)', function () {
    $calls = 0;
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) use (&$calls) {
        $calls++;
        return \GuzzleHttp\Promise\Create::rejectionFor(
            new \Aws\Exception\AwsException('cURL error 28: timed out', $cmd, ['connection_error' => true])
        );
    });
    $report = (new BucketDoctor($dm))->diagnose('s3');
    assertEqual(1, $calls, 'only the reachability call was made');
    assertEqual('fail', $report['summary'], 'summary fail');
    $by = array_column($report['checks'], null, 'id');
    assertEqual('fail', $by['reachability']['status']);
    foreach (['write', 'multipart', 'cors', 'lifecycle', 'versioning'] as $id) {
        assertEqual('info', $by[$id]['status'], "{$id} reuses info status");
        assertTrue(!empty($by[$id]['skipped']), "{$id} marked skipped");
    }
});

test('a non-connection failure (AccessDenied) does NOT skip the remaining checks', function () {
    $calls = 0;
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) use (&$calls) {
        $calls++;
        return \GuzzleHttp\Promise\Create::rejectionFor(
            new \Aws\S3\Exception\S3Exception('denied', $cmd, ['code' => 'AccessDenied'])
        );
    });
    $report = (new BucketDoctor($dm))->diagnose('s3');
    assertTrue($calls >= 5, "kept probing ({$calls} calls)");
    foreach ($report['checks'] as $c) { assertTrue(empty($c['skipped']), $c['id'] . ' not skipped'); }
});

test('an exhausted time budget skips the remaining checks', function () {
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) { return okResult($cmd); });
    assertEqual(45, (new \ReflectionClassConstant(BucketDoctor::class, 'BUDGET_SECONDS'))->getValue(), 'budget is 45 s');

    // Drive it deterministically: a subclass whose budget is gone right after reachability.
    $sub = new class($dm) extends BucketDoctor {
        protected function overBudget(): bool { return true; }
    };
    $out = $sub->diagnose('s3');
    $by = array_column($out['checks'], null, 'id');
    assertEqual('ok', $by['reachability']['status']);
    assertTrue(!empty($by['write']['skipped']) && !empty($by['cors']['skipped']), 'later checks skipped');
    assertTrue(strpos($by['write']['message'], 'time budget') !== false, 'message names the budget');
});

test('SFTP doctor disk uses a short timeout and a single attempt', function () {
    $dm = new DiskManager(['sftp' => ['driver' => 'sftp', 'host' => '8.8.8.8', 'username' => 'u', 'password' => 'p', 'timeout' => 60]]);
    $fs = $dm->sftpDoctorDisk('sftp');
    $ad = (new \ReflectionProperty($fs, 'adapter'))->getValue($fs);
    $prov = (new \ReflectionProperty($ad, 'connectionProvider'))->getValue($ad);
    assertEqual(8, (new \ReflectionProperty($prov, 'timeout'))->getValue($prov), 'timeout');
    assertEqual(1, (new \ReflectionProperty($prov, 'maxTries'))->getValue($prov), 'maxTries');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
