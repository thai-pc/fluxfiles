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
        $want = $name === 'DeleteObject' ? ['connect_timeout' => 3, 'timeout' => 8] : ['connect_timeout' => 5, 'timeout' => 15];
        assertEqual($want, $http, "{$name} @http");
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

test('probe PutObject matches what a real Flysystem upload sends (key dir, ACL, content type)', function () {
    $configs = [
        'public AWS'  => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'visibility' => 'public'],
        'private AWS' => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's'],
        'R2/endpoint' => ['driver' => 's3', 'bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://acct.r2.example', 'visibility' => 'public'],
    ];
    foreach ($configs as $label => $cfg) {
        $puts = [];
        $dm = new DiskManager(['d' => $cfg]);
        $fs = $dm->disk('d');
        $client = $dm->s3Client('d');
        $client->getHandlerList()->setHandler(function (\Aws\CommandInterface $cmd) use (&$puts) {
            if ($cmd->getName() === 'PutObject') {
                $puts[] = ['key' => $cmd['Key'], 'acl' => $cmd['ACL'] ?? null, 'ct' => $cmd['ContentType'] ?? null, 'body' => (string) $cmd['Body']];
                $GLOBALS['probeBody'] = (string) $cmd['Body'];
            }
            return okResult($cmd);
        });
        $fs->write('_fluxfiles/.doctor/ref.txt', 'x');
        (new BucketDoctor($dm, new class implements \FluxFiles\HttpTransportInterface {
            public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array
            { return ['status' => 200, 'headers' => [], 'body' => $GLOBALS['probeBody']]; }
        }))->diagnose('d');
        assertEqual(2, count($puts), "{$label}: two puts");
        [$ref, $probe] = $puts;
        assertEqual($ref['acl'], $probe['acl'], "{$label}: ACL parity");
        assertEqual($ref['ct'], $probe['ct'], "{$label}: content-type parity");
        assertEqual(0, strpos($probe['key'], '_fluxfiles/.doctor/'), "{$label}: probe dir");
        if ($label === 'public AWS') { assertEqual('public-read', $probe['acl'], 'public AWS sends public-read'); }
        else { assertEqual(null, $probe['acl'], "{$label}: no ACL sent"); }
    }
});

test('probe delete is attempted after a later connection error (and summary is not ok)', function () {
    $names = [];
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) use (&$names) {
        $names[] = $cmd->getName();
        if ($cmd->getName() === 'GetObject') {
            return \GuzzleHttp\Promise\Create::rejectionFor(new \Aws\Exception\AwsException('timeout', $cmd, ['connection_error' => true]));
        }
        return okResult($cmd);
    });
    $report = (new BucketDoctor($dm))->diagnose('s3');
    assertTrue(in_array('DeleteObject', $names, true), 'DeleteObject issued');
    assertTrue(!in_array('CreateMultipartUpload', $names, true), 'later checks skipped');
    assertTrue($report['summary'] !== 'ok', 'summary not ok after a connection error');
    assertEqual('ok', array_column($report['checks'], null, 'id')['delete']['status']);
});

test('probe delete is attempted after the time budget is exhausted', function () {
    $names = [];
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) use (&$names) { $names[] = $cmd->getName(); return okResult($cmd); });
    $sub = new class($dm) extends BucketDoctor {
        public int $n = 0;
        protected function overBudget(): bool { return ++$this->n > 1; }   // write passes, everything after is over budget
    };
    $out = $sub->diagnose('s3');
    $by = array_column($out['checks'], null, 'id');
    assertEqual('ok', $by['write']['status']);
    assertTrue(!empty($by['read']['skipped']), 'read skipped');
    assertTrue(in_array('DeleteObject', $names, true), 'DeleteObject still issued');
});

test('per-call timeouts are clamped to the remaining budget', function () {
    [$dm] = fakeS3Doctor(function (\Aws\CommandInterface $cmd) { return okResult($cmd); });
    $doctor = new BucketDoctor($dm);
    $dl = new \ReflectionProperty($doctor, 'deadline');
    $dl->setAccessible(true);
    $m = new \ReflectionMethod($doctor, 'bounds');
    $m->setAccessible(true);
    $dl->setValue($doctor, microtime(true) + 2.5);
    $http = $m->invoke($doctor)['@http'];
    assertEqual(['connect_timeout' => 3, 'timeout' => 3], $http, 'clamped to ceil(remaining)');
    $dl->setValue($doctor, microtime(true) - 10);
    assertEqual(['connect_timeout' => 1, 'timeout' => 1], $m->invoke($doctor)['@http'], 'floor of 1 s');
});

test('max_tries in a tenant/BYOB SFTP config is ignored; normal disks keep 2, doctor uses 1 and timeout >= 1', function () {
    $cfg = ['driver' => 'sftp', 'host' => '8.8.8.8', 'username' => 'u', 'password' => 'p', 'max_tries' => 1000, 'timeout' => 0];
    $dm = new DiskManager(['sftp' => $cfg]);
    $prov = function ($fs) {
        $ad = (new \ReflectionProperty($fs, 'adapter'))->getValue($fs);
        return (new \ReflectionProperty($ad, 'connectionProvider'))->getValue($ad);
    };
    $normal = $prov($dm->disk('sftp'));
    assertEqual(2, (new \ReflectionProperty($normal, 'maxTries'))->getValue($normal), 'normal disk keeps 2');
    $doc = $prov($dm->sftpDoctorDisk('sftp'));
    assertEqual(1, (new \ReflectionProperty($doc, 'maxTries'))->getValue($doc), 'doctor uses 1');
    assertEqual(1, (new \ReflectionProperty($doc, 'timeout'))->getValue($doc), 'timeout floor 1');
});

echo "\n  Total: " . ($passed + $failed) . "  {$green}Passed: {$passed}{$reset}  {$red}Failed: {$failed}{$reset}\n";
exit($failed > 0 ? 1 : 0);
