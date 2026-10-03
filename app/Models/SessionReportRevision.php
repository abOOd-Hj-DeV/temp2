<?php

namespace App\Models;

use App\Casts\ClinicalEncrypted;
use Illuminate\Database\Eloquent\Model;

class SessionReportRevision extends Model
{
    protected $table = 'booking_report_revisions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'previous_summary' => ClinicalEncrypted::class,
        'new_summary' => ClinicalEncrypted::class,
    ];
}
