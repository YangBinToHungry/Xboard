<?php

namespace App\Services;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TrafficReportService
{
    // Returns false for an already committed retry. Receipts must not be pruned
    // while an old node can still retry the corresponding report id.
    public function process(Server $node, string $reportId, array $data): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $reportId) || !$data || count($data) > 1000) {
            throw ValidationException::withMessages(['traffic' => 'Invalid report id or batch size (1..1000).']);
        }
        foreach ($data as $uid => $traffic) {
            if (!ctype_digit((string) $uid) || (int) $uid <= 0 || !is_array($traffic) || array_keys($traffic) !== [0, 1]
                || !is_int($traffic[0]) || !is_int($traffic[1]) || $traffic[0] < 0 || $traffic[1] < 0) {
                throw ValidationException::withMessages(['traffic' => 'Expected positive user ids and nonnegative integer byte counts.']);
            }
        }
        ksort($data, SORT_NUMERIC);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $server = $node->toArray();
        $server['rate'] = $node->getCurrentRate();
        $timestamp = strtotime(date('Y-m-d'));

        [$processed, $userIds] = DB::transaction(function () use ($node, $reportId, $data, $hash, $server, $timestamp) {
            $inserted = DB::table('v2_traffic_report_receipt')->insertOrIgnore([
                'server_id' => $node->id,
                'report_id' => $reportId,
                'payload_hash' => $hash,
                'created_at' => time(),
            ]);
            if (!$inserted) {
                $receipt = DB::table('v2_traffic_report_receipt')
                    ->where('server_id', $node->id)->where('report_id', $reportId)->first();
                if (!$receipt || !hash_equals($receipt->payload_hash, $hash)) {
                    throw new ConflictHttpException('Report id already belongs to a different payload.');
                }
                // Older receipts predate the saved post-filter user list.
                $userIds = $receipt->check_user_ids === null
                    ? array_keys($data)
                    : json_decode($receipt->check_user_ids, true, 512, JSON_THROW_ON_ERROR);
                return [false, $userIds];
            }

            // Run on this connection inside the receipt transaction, rather than
            // acknowledging a queue publish which could partially succeed.
            [$server, $protocol, $data] = HookManager::filter('traffic.process.before', [$server, $node->type, $data]);
            [$server, $protocol, $data] = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);
            (new TrafficFetchJob($server, $data, $protocol, $timestamp, false))->handle();
            (new StatUserJob($server, $data, $protocol, 'd'))->handle();
            (new StatServerJob($server, $data, $protocol, 'd'))->handle();
            $userIds = array_keys($data);
            DB::table('v2_traffic_report_receipt')
                ->where('server_id', $node->id)->where('report_id', $reportId)
                ->update(['check_user_ids' => json_encode($userIds, JSON_THROW_ON_ERROR)]);
            return [true, $userIds];
        }, 3);

        // Notify only after committed usage is visible. A failed notification
        // prevents the ACK; an identical retry republishes without charging again.
        DB::afterCommit(function () use ($node, $data, $userIds) {
            if ($userIds) {
                Redis::sadd('traffic:pending_check', ...$userIds);
            }
            ServerService::recordTrafficReport($node, count($data));
        });

        return $processed;
    }
}
