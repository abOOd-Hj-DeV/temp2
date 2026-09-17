<?php

// app/Jobs/CleanupUnverifiedUsersJob.php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupUnverifiedUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * عدد الساعات التي ينتظرها النظام قبل حذف المستخدم غير المؤكد
     * يمكن تمريرها كمعامل أو استخدام قيمة افتراضية
     */
    private int $hoursThreshold;

    public function __construct(int $hoursThreshold = 24)
    {
        $this->hoursThreshold = $hoursThreshold;
    }

    /**
     * تنفيذ عملية التنظيف
     */
    public function handle(): void
    {
        Log::info('بدء عملية تنظيف المستخدمين غير المؤكدين...');

        $deletedCount = User::whereNull('phone_verified_at')
            ->where('is_active', false)
            ->where('created_at', '<', now()->subHours($this->hoursThreshold))
            ->delete();

        Log::info("تم حذف {$deletedCount} حساب غير مؤكد (أقدم من {$this->hoursThreshold} ساعة).");
    }
}
