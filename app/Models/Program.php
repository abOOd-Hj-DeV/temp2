<?php

// app/Models/Program.php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'id', 'name', 'description', 'is_core',
    ];

    protected $casts = [
        'is_core' => 'boolean',
    ];

    /**
     * العلاقة مع الوحدات
     */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'program_id');
    }

    /**
     * الحصول على الوحدات مرتبة
     */
    public function orderedModules(): HasMany
    {
        return $this->modules()->orderBy('order');
    }
}
