<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\ComplaintState\ComplaintStateFactory;
use Carbon\Carbon;

class Complaint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tracking_number',
        'user_id',
        'entity_id',
        'complaint_kind',
        'description',
        'location',
        'status',
        'assigned_to',
        'locked_at',
        'lock_expires_at',
        'info_requested',
        'info_request_message',
        'info_requested_at',
        'version',
        'admin_notes',
        'resolution',
        'reviewed_at',
        'resolved_at',
    ];

    protected $casts = [
        'info_requested' => 'boolean',
        'locked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'info_requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class)->where('file_type', 'image');
    }

    public function pdfs(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class)->where('file_type', 'pdf');
    }

    /**
     * Get complaint state
     */
    public function getState()
    {
        return ComplaintStateFactory::forComplaint($this);
    }

    /**
     * Check if complaint is locked
     */
    public function isLocked(): bool
    {
        if (!$this->locked_at) {
            return false;
        }

        if ($this->lock_expires_at && $this->lock_expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Lock complaint for employee
     */
    public function lock(int $employeeId, int $durationMinutes = 30): void
    {
        $this->update([
            'locked_at' => now(),
            'lock_expires_at' => now()->addMinutes($durationMinutes),
            'assigned_to' => $employeeId,
        ]);
    }

    /**
     * Unlock complaint
     */
    public function unlock(): void
    {
        $this->update([
            'locked_at' => null,
            'lock_expires_at' => null,
        ]);
    }

    /**
     * Check if lock has expired and auto-unlock
     */
    public function checkAndUnlockIfExpired(): bool
    {
        if ($this->isLocked() && $this->lock_expires_at && $this->lock_expires_at->isPast()) {
            $this->unlock();
            return true;
        }

        return false;
    }

    /**
     * Request more info from citizen
     */
    public function requestInfo(string $message): void
    {
        $this->update([
            'info_requested' => true,
            'info_request_message' => $message,
            'info_requested_at' => now(),
        ]);
    }

    /**
     * Clear info request
     */
    public function clearInfoRequest(): void
    {
        $this->update([
            'info_requested' => false,
            'info_request_message' => null,
            'info_requested_at' => null,
        ]);
    }

    /**
     * Increment version (optimistic locking)
     */
    public function incrementVersion(): void
    {
        $this->increment('version');
    }

    // Scopes
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAssignedTo($query, int $employeeId)
    {
        return $query->where('assigned_to', $employeeId);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeUnlocked($query)
    {
        return $query->where(function($q) {
            $q->whereNull('locked_at')
                ->orWhere('lock_expires_at', '<', now());
        });
    }
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'tracking_number',
                'status',
                'assigned_to',
                'complaint_kind',
                'description',
                'location',
                'info_requested',
                'resolution',
                'admin_notes',
                'resolved_at'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Complaint {$this->tracking_number} was {$eventName}");
    }
}
