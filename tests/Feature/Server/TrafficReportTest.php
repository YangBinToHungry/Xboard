<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\TrafficReportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TrafficReportTest extends TestCase
{
    // Real commits are required to verify visibility from another connection.
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

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
        Redis::shouldReceive('sadd')->twice()->andReturn(1);
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

    public function test_notification_failure_keeps_committed_usage_and_retry_republishes(): void
    {
        Redis::shouldReceive('sadd')->once()->andThrow(new \RuntimeException('injected Redis failure'));
        $node = $this->makeNode(); $user = $this->makeUser(); $service = app(TrafficReportService::class);
        $id = str_repeat('b', 32); $data = [$user->id => [10, 20]];
        try { $service->process($node, $id, $data); $this->fail('Expected injected failure'); }
        catch (\RuntimeException $e) { $this->assertSame('injected Redis failure', $e->getMessage()); }
        $this->assertSame(10, (int) $user->fresh()->u);
        $this->assertSame(1, DB::table('v2_traffic_report_receipt')->count());
        Redis::shouldReceive('sadd')->once()->andReturn(1);
        $this->assertFalse($service->process($node, $id, $data));
        $this->assertSame(10, (int) $user->fresh()->u);
        $this->assertSame(10, (int) DB::table('v2_stat_user')->sum('u'));
        $this->assertSame(20, (int) DB::table('v2_stat_server')->sum('d'));
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
        Redis::shouldReceive('sadd')->twice()->andReturn(1);
        admin_setting(['server_token' => 'test-server-token']);
        $node = $this->makeNode(); $user = $this->makeUser();
        $url = '/api/v1/server/UniProxy/push?token=test-server-token&node_id=' . $node->id . '&node_type=vmess';
        $data = [$user->id => [10, 20]]; $id = str_repeat('f', 32);
        $this->postJson($url, $data, ['X-Traffic-Report-ID' => $id])->assertOk()->assertHeader('X-Traffic-Report-Ack', $id);
        $this->assertSame(1, $node->fresh()->online);
        $this->assertGreaterThanOrEqual(time() - 2, $node->fresh()->last_push_at);
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_PUSH_AT', $node->id), time() - 600, 3600);
        Cache::put(CacheKey::get('SERVER_VMESS_ONLINE_USER', $node->id), 0, 3600);
        $this->postJson($url, $data, ['X-Traffic-Report-ID' => $id])->assertOk()->assertHeader('X-Traffic-Report-Ack', $id);
        $this->assertSame(1, $node->fresh()->online);
        $this->assertGreaterThanOrEqual(time() - 2, $node->fresh()->last_push_at);
        Cache::put(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $node->id), time(), 3600);
        $this->assertSame(Server::STATUS_ONLINE, $node->fresh()->available_status);
        $this->assertSame(10, (int) $user->fresh()->u);
        \Illuminate\Support\Facades\Bus::fake();
        $this->postJson($url, $data)->assertOk();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\TrafficFetchJob::class);
    }


    public function test_notifications_observe_committed_usage_on_an_independent_connection(): void
    {
        $node = $this->makeNode(); $user = $this->makeUser();
        config()->set('database.connections.traffic_observer', config('database.connections.' . config('database.default')));
        Redis::shouldReceive('sadd')->once()->andReturnUsing(function ($key, ...$ids) use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('traffic:pending_check', $key);
            $this->assertSame([$user->id], $ids);
            $this->assertSame(10, (int) DB::connection('traffic_observer')->table('v2_user')->where('id', $user->id)->value('u'));
            return 1;
        });
        try {
            app(TrafficReportService::class)->process($node, str_repeat('1', 32), [$user->id => [10, 20]]);
        } finally {
            DB::purge('traffic_observer');
        }
    }

    public function test_statistics_failure_rolls_back_usage_and_receipt_without_notification(): void
    {
        $node = $this->makeNode(); $user = $this->makeUser();
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'v2_stat_server') && str_starts_with(strtolower($query->sql), 'insert')) {
                $armed = false;
                throw new \RuntimeException('injected statistics failure');
            }
        });
        Redis::shouldReceive('sadd')->once()->andReturnUsing(function () use ($user) {
            $this->assertSame(10, (int) $user->fresh()->u);
            return 1;
        });
        $service = app(TrafficReportService::class);
        $id = str_repeat('2', 32); $data = [$user->id => [10, 20]];
        try {
            $service->process($node, $id, $data);
            $this->fail('Expected statistics failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected statistics failure', $e->getMessage());
        }
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertSame(0, DB::table('v2_traffic_report_receipt')->count());
        $this->assertSame(0, DB::table('v2_stat_user')->count());
        $this->assertSame(0, DB::table('v2_stat_server')->count());
        $this->assertNull($node->fresh()->last_push_at);
        $this->assertTrue($service->process($node, $id, $data));
    }

    public function test_retry_uses_saved_filtered_user_ids_without_rerunning_hooks(): void
    {
        $node = $this->makeNode(); $original = $this->makeUser(); $charged = $this->makeUser();
        $calls = 0;
        HookManager::registerFilter('traffic.before_process', function ($args) use ($charged, &$calls) {
            $calls++;
            $args[2] = [$charged->id => [10, 20]];
            return $args;
        });
        Redis::shouldReceive('sadd')->with('traffic:pending_check', $charged->id)->once()
            ->andThrow(new \RuntimeException('notification unavailable'));
        $service = app(TrafficReportService::class);
        $id = str_repeat('3', 32); $data = [$original->id => [10, 20]];
        try { $service->process($node, $id, $data); $this->fail('Expected notification failure'); }
        catch (\RuntimeException $e) { $this->assertSame('notification unavailable', $e->getMessage()); }
        HookManager::remove('traffic.before_process');
        Redis::shouldReceive('sadd')->with('traffic:pending_check', $charged->id)->once()->andReturn(1);
        $this->assertFalse($service->process($node, $id, $data));
        $this->assertSame(1, $calls);
        $this->assertSame(0, (int) $original->fresh()->u);
        $this->assertSame(10, (int) $charged->fresh()->u);
    }

    public function test_old_receipt_retries_notify_original_users_without_recharging(): void
    {
        $node = $this->makeNode(); $user = $this->makeUser();
        $id = str_repeat('4', 32); $data = [$user->id => [10, 20]];
        DB::table('v2_traffic_report_receipt')->insert([
            'server_id' => $node->id, 'report_id' => $id,
            'payload_hash' => hash('sha256', json_encode($data)), 'created_at' => time(),
        ]);
        Redis::shouldReceive('sadd')->with('traffic:pending_check', $user->id)->once()->andReturn(1);
        $this->assertFalse(app(TrafficReportService::class)->process($node, $id, $data));
        $this->assertSame(0, (int) $user->fresh()->u);
    }
}
