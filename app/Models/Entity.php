<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;

class Entity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'name_ar',
        'email',
        'phone',
        'description',
        'description_ar',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get complaints for this entity
     */
    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }

    /**
     * Get employees (users) for this entity
     * One entity has many employees
     */
    public function employees(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'employee');
    }

    /**
     * Get all users for this entity (including non-employees if needed)
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Scope: Only active entities
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Get total employees count
     */
    public function getEmployeesCountAttribute(): int
    {
        return $this->employees()->count();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'name_ar',
                'email',
                'phone',
                'description',
                'description_ar',
                'type',
                'is_active'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Entity {$this->name} was {$eventName}");
    }
}
