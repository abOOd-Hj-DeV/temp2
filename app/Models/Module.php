<?php

// app/Models/Module.php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'id', 'program_id', 'title', 'description',
        'content_type', 'body', 'media_url', 'exercise', 'homework_prompt',
        'tracking_tools', 'order', 'is_hideable',
    ];

    protected $casts = [
        'tracking_tools' => 'array',
        'is_hideable' => 'boolean',
    ];

    /**
     * العلاقة مع البرنامج
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    /**
     * العلاقة مع مرضى أكملوا الوحدة
     */
    public function patientModules(): HasMany
    {
        return $this->hasMany(PatientModule::class, 'module_id');
    }

    /**
     * الحصول على عدد المرضى الذين أكملوا الوحدة
     */
    public function getCompletedCountAttribute(): int
    {
        return $this->patientModules()->where('status', 'completed')->count();
    }
}
