<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Repositories\StatisticsRepository;
use App\Services\Export\ExportService;
use App\Services\FcmService;
use App\Services\RateLimitControlService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

// Repositories
use App\Repositories\UserRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\EmailVerificationRepository;
use App\Repositories\PendingRegistrationRepository; // ADDED
use App\Repositories\EntityRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\ComplaintRepository;
use App\Repositories\ComplaintAttachmentRepository;

// Services
use App\Services\AuthService;
use App\Services\AdminAuthService;
use App\Services\EmailVerificationService;
use App\Services\EntityService;
use App\Services\EmployeeService;
use App\Services\ComplaintService;
use App\Services\FileUpload\FileUploadService;

// Utilities
use App\Services\TrackingNumber\TrackingNumber;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ============================================
        // REGISTER REPOSITORIES
        // ============================================
        $this->app->singleton(StatisticsRepository::class);
        $this->app->singleton(ExportService::class);
        $this->app->singleton(RateLimitControlService::class);
        $this->app->singleton(UserRepository::class, function ($app) {
            return new UserRepository();
        });

        $this->app->singleton(RefreshTokenRepository::class, function ($app) {
            return new RefreshTokenRepository();
        });

        $this->app->singleton(EmailVerificationRepository::class, function ($app) {
            return new EmailVerificationRepository();
        });

        // ADDED: Pending Registration Repository
        $this->app->singleton(PendingRegistrationRepository::class, function ($app) {
            return new PendingRegistrationRepository();
        });

        $this->app->singleton(EntityRepository::class, function ($app) {
            return new EntityRepository();
        });

        $this->app->singleton(EmployeeRepository::class, function ($app) {
            return new EmployeeRepository();
        });

        $this->app->singleton(ComplaintRepository::class, function ($app) {
            return new ComplaintRepository();
        });

        $this->app->singleton(ComplaintAttachmentRepository::class, function ($app) {
            return new ComplaintAttachmentRepository();
        });

        // ============================================
        // REGISTER UTILITIES
        // ============================================

        $this->app->singleton(TrackingNumber::class, function ($app) {
            return new TrackingNumber();
        });

        $this->app->singleton(FileUploadService::class, function ($app) {
            return new FileUploadService();
        });

        $this->app->singleton(FcmService::class, function ($app) {
            return new FcmService();
        });

        // ============================================
        // REGISTER SERVICES
        // ============================================

        // Email Verification Service (still used for legacy flows if needed)
        $this->app->singleton(EmailVerificationService::class, function ($app) {
            return new EmailVerificationService(
                $app->make(EmailVerificationRepository::class),
                $app->make(UserRepository::class)
            );
        });

        // Auth Service (for citizens/companies)
        // UPDATED: Now uses PendingRegistrationRepository
        $this->app->singleton(AuthService::class, function ($app) {
            return new AuthService(
                $app->make(UserRepository::class),
                $app->make(RefreshTokenRepository::class),
                $app->make(PendingRegistrationRepository::class) // CHANGED
            );
        });

        // Admin Auth Service
        $this->app->singleton(AdminAuthService::class, function ($app) {
            return new AdminAuthService(
                $app->make(UserRepository::class),
                $app->make(RefreshTokenRepository::class)
            );
        });

        // Entity Service
        $this->app->singleton(EntityService::class, function ($app) {
            return new EntityService(
                $app->make(EntityRepository::class)
            );
        });

        // Employee Service
        $this->app->singleton(EmployeeService::class, function ($app) {
            return new EmployeeService(
                $app->make(EmployeeRepository::class),
                $app->make(EntityRepository::class)
            );
        });

        // Complaint Service
        $this->app->singleton(ComplaintService::class, function ($app) {
            return new ComplaintService(
                $app->make(ComplaintRepository::class),
                $app->make(ComplaintAttachmentRepository::class),
                $app->make(TrackingNumber::class),
                $app->make(FileUploadService::class),

            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for older MySQL/MariaDB versions
        Schema::defaultStringLength(191);
    }
}
