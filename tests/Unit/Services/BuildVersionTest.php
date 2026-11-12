<?php

namespace Tests\Unit\Services;

use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BuildVersionTest extends TestCase
{
    public function test_image_metadata_takes_precedence_over_a_stale_cached_version(): void
    {
        $directory = sys_get_temp_dir() . '/xboard-version-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $original = base_path();
        try {
            app()->setBasePath($directory);
            file_put_contents($directory . '/build-info.json', json_encode(['commit' => str_repeat('a', 40), 'date' => '20261111']));
            Cache::forever(UpdateService::CACHE_VERSION, 'stale');
            Cache::forever(UpdateService::CACHE_VERSION_DATE, '20000101');
            $service = app(UpdateService::class);
            $this->assertSame('20261111-aaaaaaa', $service->getCurrentVersion());
            $service->updateVersionCache();
            $this->assertSame('aaaaaaa', Cache::get(UpdateService::CACHE_VERSION));
        } finally {
            app()->setBasePath($original);
            unlink($directory . '/build-info.json');
            rmdir($directory);
        }
    }
}
