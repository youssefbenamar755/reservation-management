<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UsefulAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanUsefulAlerts extends Command
{
    protected $signature = 'alerts:scan';

    protected $description = 'Check stored webhook, email and order records for grouped in-app alerts';

    public function handle(UsefulAlerts $alerts): int
    {
        $failed = false;
        User::select('id', 'is_admin')->chunkById(50, function ($users) use ($alerts, &$failed) {
            foreach ($users as $user) {
                try {
                    $alerts->scan($user);
                } catch (Throwable $exception) {
                    $failed = true;
                    Log::warning('Useful alert scan did not complete', ['user_id' => $user->id, 'exception_type' => $exception::class]);
                }
            }
        });
        $this->info($failed ? 'Some alert checks did not complete; their prior state was retained.' : 'Stored-record alert checks completed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
