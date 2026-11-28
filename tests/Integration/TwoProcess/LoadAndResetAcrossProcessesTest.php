<?php

declare(strict_types=1);

namespace WeDevelop\Fixtures\Tests\Integration\TwoProcess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\Reset\DatabaseReset;

/**
 * A load from the CLI and a reset over HTTP share the ledger, because it lives in the database. Runs against the
 * harness app's own database and web server, outside SapphireTest's temporary database.
 */
#[CoversClass(FixtureLoader::class)]
#[CoversClass(DatabaseReset::class)]
final class LoadAndResetAcrossProcessesTest extends TestCase
{
    private const string SCRIPTS = __DIR__ . '/scripts/';

    private string $endpoint = '';

    protected function setUp(): void
    {
        // The web server only serves files under the public root: expose the reset script there for this test
        $this->endpoint = '_fixtures_reset_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents(
            '/app/public/' . $this->endpoint,
            sprintf("<?php\nrequire %s;\n", var_export(self::SCRIPTS . 'reset.php', true)),
        );
    }

    protected function tearDown(): void
    {
        unlink('/app/public/' . $this->endpoint);
        // The reset under test may not have run: never leave the load in the harness's database
        $this->cli('reset');
    }

    public function testRecordsLoadedByOneProcessAreRemovedByAResetOverHttp(): void
    {
        $ids = json_decode($this->cli('load', __DIR__ . '/groups.yml'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($ids);
        $this->assertCount(2, $ids);
        $this->assertSame('2', $this->cli('count', implode(',', $ids)));

        $response = file_get_contents(
            'https://localhost/' . $this->endpoint,
            false,
            stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
        );

        $this->assertIsString($response);
        $this->assertStringStartsWith('reset by ', $response);
        $this->assertSame('0', $this->cli('count', implode(',', $ids)));
    }

    private function cli(string $command, string $argument = ''): string
    {
        $process = proc_open(
            ['php', self::SCRIPTS . 'cli.php', $command, $argument],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors . $output);

        return trim($output);
    }
}
