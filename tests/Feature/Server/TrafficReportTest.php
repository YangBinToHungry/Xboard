<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\TrafficReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TrafficReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(): Server
    {
        return Server::create(['name' => 'receipt-test', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => '1',
            'group_id' => [1], 'enabled' => true]);
    }

    private function makeUser(): User
    {
        return User::create(['email' => uniqid('traffic-') . '@example.com', 'password' => 'test',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => \Illuminate\Support\Str::random(32),
            'u' => 0, 'd' => 0, 'transfer_enable' => 1000000]);
    }

    public function test_lost_response_retry_only_charges_and_updates_statistics_once(): void
    {
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        $node = $this->makeNode(); $user = $this->makeUser(); $service = app(TrafficReportService::class);
        $data = [$user->id => [10, 20]]; $id = str_repeat('a', 32);
        $this->assertTrue($service->process($node, $id, $data));
        $this->assertFalse($service->process($node, $id, $data));
        $this->assertSame(10, (int) $user->fresh()->u);
        $this->assertSame(20, (int) $user->fresh()->d);
        $this->assertSame(10, (int) $node->fresh()->u);
        $this->assertSame(10, (int) DB::table('v2_stat_user')->sum('u'));
        $this->assertSame(20, (int) DB::table('v2_stat_server')->sum('d'));
        $this->assertSame(1, DB::table('v2_traffic_report_receipt')->count());
    }

    public function test_failure_rolls_back_receipt_and_partial_usage_before_retry(): void
    {
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('injected Redis failure'));
        $node = $this->makeNode(); $user = $this->makeUser(); $service = app(TrafficReportService::class);
        $id = str_repeat('b', 32); $data = [$user->id => [10, 20]];
        try { $service->process($node, $id, $data); $this->fail('Expected injected failure'); }
        catch (\RuntimeException $e) { $this->assertSame('injected Redis failure', $e->getMessage()); }
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, DB::table('v2_traffic_report_receipt')->count());
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        $this->assertTrue($service->process($node, $id, $data));
        $this->assertSame(10, (int) $user->fresh()->u);
    }

    public function test_reused_id_with_different_payload_is_rejected(): void
    {
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        $node = $this->makeNode(); $user = $this->makeUser(); $service = app(TrafficReportService::class);
        $id = str_repeat('c', 32);
        $service->process($node, $id, [$user->id => [10, 20]]);
        $this->expectException(ConflictHttpException::class);
        $service->process($node, $id, [$user->id => [11, 20]]);
    }

    public function test_id_namespace_is_per_node(): void
    {
        Redis::shouldReceive('sadd')->twice()->andReturn(1);
        $a = $this->makeNode(); $b = $this->makeNode(); $user = $this->makeUser();
        $service = app(TrafficReportService::class); $id = str_repeat('d', 32);
        $this->assertTrue($service->process($a, $id, [$user->id => [10, 0]]));
        $this->assertTrue($service->process($b, $id, [$user->id => [10, 0]]));
        $this->assertSame(20, (int) $user->fresh()->u);
    }

    public function test_malformed_batch_never_creates_a_receipt(): void
    {
        $node = $this->makeNode();
        $this->expectException(ValidationException::class);
        app(TrafficReportService::class)->process($node, str_repeat('e', 32), [1 => [-1, 0]]);
    }
    public function test_http_batch_retries_are_acknowledged_and_legacy_nodes_still_queue(): void
    {
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        admin_setting(['server_token' => 'test-server-token']);
        $node = $this->makeNode(); $user = $this->makeUser();
        $url = '/api/v1/server/UniProxy/push?token=test-server-token&node_id=' . $node->id . '&node_type=vmess';
        $data = [$user->id => [10, 20]]; $id = str_repeat('f', 32);
        $this->postJson($url, $data, ['X-Traffic-Report-ID' => $id])->assertOk()->assertHeader('X-Traffic-Report-Ack', $id);
        $this->postJson($url, $data, ['X-Traffic-Report-ID' => $id])->assertOk()->assertHeader('X-Traffic-Report-Ack', $id);
        $this->assertSame(10, (int) $user->fresh()->u);
        \Illuminate\Support\Facades\Bus::fake();
        $this->postJson($url, $data)->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\TrafficFetchJob::class);
    }

}
