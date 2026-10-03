<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;

class ClinicalNotificationEvent extends Model
{
    use HasUUID;

    protected $fillable = ['patient_id', 'kind', 'entity_id', 'delivered_at'];

    protected $casts = ['delivered_at' => 'datetime'];
}
