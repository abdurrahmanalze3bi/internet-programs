<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use App\Repositories\ComplaintRepository;
use App\Repositories\ComplaintAttachmentRepository;
use App\Services\TrackingNumber\TrackingNumber;
use App\Services\FileUpload\FileUploadService;
use App\Services\FileUpload\ImageUploadStrategy;
use App\Services\FileUpload\PdfUploadStrategy;
use App\Services\ComplaintState\ComplaintStateFactory;
use App\Events\ComplaintCreated;
use App\Events\ComplaintStatusChanged;
use App\Notifications\ComplaintCreatedNotification;
use App\Notifications\ComplaintStatusChangedNotification;
use App\Notifications\InfoRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ComplaintService
{
    public function __construct(
        private ComplaintRepository $complaintRepository,
        private ComplaintAttachmentRepository $attachmentRepository,
        private TrackingNumber $trackingNumberGenerator,
        private FileUploadService $fileUploadService
    ) {}

    /**
     * Check if notifications should be sent
     */
    private function shouldSendNotifications(): bool
    {
        // Don't send notifications if:
        // 1. Email verification is in bypass mode (development)
        // 2. App is in local/development environment
        return !config('auth.verification.bypass_enabled', false)
            && !app()->environment('local');
    }

    /**
     * Send notification safely (only if enabled)
     */
    private function sendNotification($notifiable, $notification): void
    {
        if ($this->shouldSendNotifications()) {
            $notifiable->notify($notification);
        } else {
            Log::info('Notification skipped (dev mode)', [
                'notification' => get_class($notification),
                'recipient' => $notifiable->email ?? $notifiable->id,
            ]);
        }
    }

    /**
     * Create a new complaint
     */
    public function createComplaint(array $data, ?array $images = null, ?array $pdfs = null): Complaint
    {
        DB::beginTransaction();

        try {
            $this->validateFileLimits($images, $pdfs);

            $trackingNumber = $this->trackingNumberGenerator->generate();

            $complaint = $this->complaintRepository->create([
                'tracking_number' => $trackingNumber,
                'user_id' => $data['user_id'],
                'entity_id' => $data['entity_id'],
                'complaint_kind' => $data['complaint_kind'],
                'description' => $data['description'],
                'location' => $data['location'],
                'status' => 'new',
                'version' => 1,
            ]);

            if ($images && count($images) > 0) {
                $this->uploadImages($complaint, $images);
            }

            if ($pdfs && count($pdfs) > 0) {
                $this->uploadPdfs($complaint, $pdfs);
            }

            DB::commit();

            event(new ComplaintCreated($complaint));

            // Send notification only if not in bypass mode
            $this->sendNotification($complaint->user, new ComplaintCreatedNotification($complaint));

            Log::info('Complaint created', ['tracking_number' => $trackingNumber]);

            return $complaint->load(['attachments', 'entity']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create complaint', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update complaint (only for 'new' or 'declined' status)
     */
    public function updateComplaint(Complaint $complaint, array $data, User $user, ?array $images = null, ?array $pdfs = null): Complaint
    {
        DB::beginTransaction();

        try {
            if ($user->role === 'citizen' && $complaint->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'complaint' => ['You can only update your own complaints.']
                ]);
            }

            $state = $complaint->getState();

            if (!$state->canBeUpdatedByCitizen($complaint)) {
                throw ValidationException::withMessages([
                    'status' => ['Complaint cannot be updated in current status: ' . $complaint->status]
                ]);
            }

            // Validate file limits
            $currentImages = $complaint->images()->count();
            $currentPdfs = $complaint->pdfs()->count();
            $newImagesCount = $images ? count($images) : 0;
            $newPdfsCount = $pdfs ? count($pdfs) : 0;

            if (($currentImages + $newImagesCount) > 5) {
                throw ValidationException::withMessages([
                    'images' => ['Total images cannot exceed 5.']
                ]);
            }

            if (($currentPdfs + $newPdfsCount) > 5) {
                throw ValidationException::withMessages([
                    'pdfs' => ['Total PDFs cannot exceed 5.']
                ]);
            }

            // Update complaint data
            $updateData = [];
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            if (isset($data['complaint_kind'])) {
                $updateData['complaint_kind'] = $data['complaint_kind'];
            }
            if (isset($data['location'])) {
                $updateData['location'] = $data['location'];
            }

            // If updating a declined complaint, change status back to 'new'
            $oldStatus = null;
            if ($complaint->status === 'declined') {
                $oldStatus = $complaint->status;
                $updateData['status'] = 'new';
                $updateData['assigned_to'] = null;
                $updateData['locked_at'] = null;
                $updateData['lock_expires_at'] = null;
            }

            // If info was requested and now providing info
            if ($complaint->info_requested) {
                $complaint->clearInfoRequest();
            }

            $complaint->incrementVersion();
            $this->complaintRepository->update($complaint, $updateData);

            // Upload new files
            if ($images && count($images) > 0) {
                $this->uploadImages($complaint, $images);
            }

            if ($pdfs && count($pdfs) > 0) {
                $this->uploadPdfs($complaint, $pdfs);
            }

            DB::commit();

            // Notify if status changed
            if ($oldStatus && $oldStatus !== $complaint->status) {
                event(new ComplaintStatusChanged($complaint, $oldStatus, $complaint->status));
                $this->sendNotification(
                    $complaint->user,
                    new ComplaintStatusChangedNotification($complaint, $oldStatus, $complaint->status)
                );
            }

            Log::info('Complaint updated', [
                'tracking_number' => $complaint->tracking_number,
                'user_id' => $user->id,
            ]);

            return $complaint->fresh()->load(['attachments', 'entity']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update complaint', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Employee accepts complaint (changes status to in_progress)
     */
    public function acceptComplaint(Complaint $complaint, User $employee): Complaint
    {
        DB::beginTransaction();

        try {
            // Verify employee belongs to complaint's entity
            if ($employee->entity_id !== $complaint->entity_id) {
                throw ValidationException::withMessages([
                    'entity' => ['You can only accept complaints for your entity.']
                ]);
            }

            // Check if complaint can be accepted
            $state = $complaint->getState();
            if (!$state->canBeAcceptedByEmployee($complaint)) {
                throw ValidationException::withMessages([
                    'status' => ['Complaint cannot be accepted in current status.']
                ]);
            }

            // Check if already locked by another employee
            if ($complaint->isLocked() && $complaint->assigned_to !== $employee->id) {
                throw ValidationException::withMessages([
                    'locked' => ['This complaint is currently being handled by another employee.']
                ]);
            }

            $oldStatus = $complaint->status;

            // Lock complaint and change status
            $complaint->lock($employee->id, 480); // 8 hours lock
            $complaint->incrementVersion();

            $this->complaintRepository->update($complaint, [
                'status' => 'in_progress',
                'reviewed_at' => now(),
            ]);

            DB::commit();

            // Notify citizen
            event(new ComplaintStatusChanged($complaint, $oldStatus, 'in_progress'));
            $this->sendNotification(
                $complaint->user,
                new ComplaintStatusChangedNotification($complaint, $oldStatus, 'in_progress')
            );

            Log::info('Complaint accepted', [
                'tracking_number' => $complaint->tracking_number,
                'employee_id' => $employee->id,
            ]);

            return $complaint->fresh()->load(['assignedEmployee', 'entity']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept complaint', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Employee finishes complaint
     */
    public function finishComplaint(Complaint $complaint, User $employee, string $resolution): Complaint
    {
        DB::beginTransaction();

        try {
            // Verify employee is assigned to this complaint
            if ($complaint->assigned_to !== $employee->id) {
                throw ValidationException::withMessages([
                    'assignment' => ['You can only finish complaints assigned to you.']
                ]);
            }

            // Check if state allows finishing
            $state = $complaint->getState();
            if (!$state->canChangeStatus($complaint, 'finished', $employee)) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot finish complaint in current status.']
                ]);
            }

            $oldStatus = $complaint->status;

            $complaint->unlock();
            $complaint->incrementVersion();

            $this->complaintRepository->update($complaint, [
                'status' => 'finished',
                'resolution' => $resolution,
                'resolved_at' => now(),
            ]);

            DB::commit();

            // Notify citizen
            event(new ComplaintStatusChanged($complaint, $oldStatus, 'finished'));
            $this->sendNotification(
                $complaint->user,
                new ComplaintStatusChangedNotification($complaint, $oldStatus, 'finished')
            );

            Log::info('Complaint finished', [
                'tracking_number' => $complaint->tracking_number,
                'employee_id' => $employee->id,
            ]);

            return $complaint->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to finish complaint', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Employee declines complaint
     */
    public function declineComplaint(Complaint $complaint, User $employee, string $reason): Complaint
    {
        DB::beginTransaction();

        try {
            // Verify employee belongs to complaint's entity
            if ($employee->entity_id !== $complaint->entity_id) {
                throw ValidationException::withMessages([
                    'entity' => ['You can only decline complaints for your entity.']
                ]);
            }

            // Check if state allows declining
            $state = $complaint->getState();
            if (!$state->canChangeStatus($complaint, 'declined', $employee)) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot decline complaint in current status.']
                ]);
            }

            $oldStatus = $complaint->status;

            $complaint->unlock();
            $complaint->incrementVersion();

            $this->complaintRepository->update($complaint, [
                'status' => 'declined',
                'admin_notes' => $reason,
                'assigned_to' => null,
            ]);

            DB::commit();

            // Notify citizen
            event(new ComplaintStatusChanged($complaint, $oldStatus, 'declined'));
            $this->sendNotification(
                $complaint->user,
                new ComplaintStatusChangedNotification($complaint, $oldStatus, 'declined')
            );

            Log::info('Complaint declined', [
                'tracking_number' => $complaint->tracking_number,
                'employee_id' => $employee->id,
            ]);

            return $complaint->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to decline complaint', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Employee requests more info from citizen
     */
    public function requestMoreInfo(Complaint $complaint, User $employee, string $message): Complaint
    {
        DB::beginTransaction();

        try {
            // Verify employee is assigned to this complaint
            if ($complaint->assigned_to !== $employee->id) {
                throw ValidationException::withMessages([
                    'assignment' => ['You can only request info for complaints assigned to you.']
                ]);
            }

            // Must be in progress
            if ($complaint->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => ['Can only request info for in-progress complaints.']
                ]);
            }

            $complaint->requestInfo($message);
            $complaint->incrementVersion();

            DB::commit();

            // Notify citizen
            $this->sendNotification($complaint->user, new InfoRequestedNotification($complaint));

            Log::info('Info requested for complaint', [
                'tracking_number' => $complaint->tracking_number,
                'employee_id' => $employee->id,
            ]);

            return $complaint->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to request info', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get complaints for entity (employee view)
     */
    public function getEntityComplaints(int $entityId, ?string $status = null, int $perPage = 15)
    {
        return $this->complaintRepository->getEntityComplaints($entityId, $status, $perPage);
    }

    /**
     * Get complaints assigned to employee
     */
    public function getEmployeeAssignedComplaints(int $employeeId, int $perPage = 15)
    {
        return $this->complaintRepository->getEmployeeAssignedComplaints($employeeId, $perPage);
    }

    /**
     * Get complaint by tracking number
     */
    public function getByTrackingNumber(string $trackingNumber): ?Complaint
    {
        $complaint = $this->complaintRepository->findByTrackingNumber($trackingNumber);

        // Check and unlock expired locks
        if ($complaint) {
            $complaint->checkAndUnlockIfExpired();
        }

        return $complaint;
    }

    /**
     * Get user's complaints
     */
    public function getUserComplaints(int $userId, int $perPage = 15)
    {
        return $this->complaintRepository->getUserComplaints($userId, $perPage);
    }

    /**
     * Auto-unlock expired complaint locks (to be run by scheduler)
     */
    public function unlockExpiredComplaints(): int
    {
        $expiredComplaints = Complaint::where('status', 'in_progress')
            ->whereNotNull('locked_at')
            ->where('lock_expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expiredComplaints as $complaint) {
            $complaint->unlock();
            $count++;

            Log::info('Auto-unlocked expired complaint', [
                'tracking_number' => $complaint->tracking_number,
            ]);
        }

        return $count;
    }

    // Private helper methods...
    private function validateFileLimits(?array $images, ?array $pdfs): void
    {
        if ($images && count($images) > 5) {
            throw ValidationException::withMessages([
                'images' => ['You can upload a maximum of 5 images.']
            ]);
        }

        if ($pdfs && count($pdfs) > 5) {
            throw ValidationException::withMessages([
                'pdfs' => ['You can upload a maximum of 5 PDFs.']
            ]);
        }
    }

    private function uploadImages(Complaint $complaint, array $images): void
    {
        $this->fileUploadService->setStrategy(new ImageUploadStrategy());
        $path = 'complaints/' . $complaint->id . '/images';

        $attachments = [];
        foreach ($images as $image) {
            $fileData = $this->fileUploadService->upload($image, $path);
            $fileData['complaint_id'] = $complaint->id;
            $fileData['created_at'] = now();
            $fileData['updated_at'] = now();
            $attachments[] = $fileData;
        }

        $this->attachmentRepository->createMany($attachments);
    }

    private function uploadPdfs(Complaint $complaint, array $pdfs): void
    {
        $this->fileUploadService->setStrategy(new PdfUploadStrategy());
        $path = 'complaints/' . $complaint->id . '/pdfs';

        $attachments = [];
        foreach ($pdfs as $pdf) {
            $fileData = $this->fileUploadService->upload($pdf, $path);
            $fileData['complaint_id'] = $complaint->id;
            $fileData['created_at'] = now();
            $fileData['updated_at'] = now();
            $attachments[] = $fileData;
        }

        $this->attachmentRepository->createMany($attachments);
    }
}
