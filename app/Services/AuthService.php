<?php
// app/Services/AuthService.php

namespace App\Services;

use App\Models\User;
use App\Models\PendingRegistration;
use App\Repositories\UserRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\PendingRegistrationRepository;
use App\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private RefreshTokenRepository $refreshTokenRepository,
        private PendingRegistrationRepository $pendingRegistrationRepository
    ) {}

    /**
     * Register a new user (stores temporarily until email verified)
     * User is NOT created in users table until they verify their email
     */
    public function register(array $data): array
    {
        // Check if email already exists in users table
        if ($this->userRepository->emailExists($data['email'])) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered and verified.']
            ]);
        }

        // Check if bypass mode is enabled
        $bypassEnabled = config('auth.verification.bypass_enabled', false);

        // Check if there's already a pending registration
        $existing = $this->pendingRegistrationRepository->findByEmail($data['email']);
        if ($existing && !$existing->isExpired()) {
            throw ValidationException::withMessages([
                'email' => ['A verification code has already been sent to this email. Please check your inbox or request a resend.']
            ]);
        }

        try {
            // Delete any old pending registrations for this email
            $this->pendingRegistrationRepository->deleteByEmail($data['email']);

            // Create pending registration with verification code
            $pendingRegistration = $this->pendingRegistrationRepository->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'] ?? 'citizen',
                'code' => PendingRegistration::generateCode(),
                'expires_at' => Carbon::now()->addMinutes(
                    config('auth.verification.expire', 60)
                ),
            ]);

            // Send verification email ONLY if bypass is disabled
            if (!$bypassEnabled) {
                Mail::to($pendingRegistration->email)->send(
                    new VerifyEmailMail($pendingRegistration)
                );
            }

            $message = 'Registration initiated! ';

            if ($bypassEnabled) {
                $message .= 'DEVELOPMENT MODE: Use any 6-digit code to verify. Your code is: ' . $pendingRegistration->code;
            } else {
                $message .= 'A 6-digit verification code has been sent to your email. Please verify to complete registration.';
            }

            return [
                'email' => $pendingRegistration->email,
                'message' => $message,
            ];

        } catch (\Exception $e) {
            // Clean up if email fails
            if (isset($pendingRegistration)) {
                $pendingRegistration->delete();
            }
            throw new \Exception('Registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify email and CREATE the user account
     * This is when the user is actually created in the database
     */
    public function verifyEmail(string $email, string $code): array
    {
        // Check if bypass mode is enabled
        $bypassEnabled = config('auth.verification.bypass_enabled', false);

        if ($bypassEnabled) {
            // BYPASS MODE: Accept any 6-digit code
            // Just find the pending registration by email
            $pendingRegistration = $this->pendingRegistrationRepository->findByEmail($email);

            if (!$pendingRegistration) {
                throw ValidationException::withMessages([
                    'email' => ['No pending registration found for this email.']
                ]);
            }

            // Check if code is 6 digits
            if (!preg_match('/^\d{6}$/', $code)) {
                throw ValidationException::withMessages([
                    'code' => ['Verification code must be exactly 6 digits.']
                ]);
            }

            // Accept any 6-digit code in bypass mode
        } else {
            // NORMAL MODE: Verify the actual code
            $pendingRegistration = $this->pendingRegistrationRepository->findByEmailAndCode($email, $code);

            if (!$pendingRegistration) {
                throw ValidationException::withMessages([
                    'code' => ['Invalid or expired verification code.']
                ]);
            }
        }

        try {
            return DB::transaction(function () use ($pendingRegistration, $bypassEnabled) {
                // NOW create the actual user with email already verified
                $user = $this->userRepository->create([
                    'first_name' => $pendingRegistration->first_name,
                    'last_name' => $pendingRegistration->last_name,
                    'email' => $pendingRegistration->email,
                    'password' => $pendingRegistration->password, // Already hashed
                    'role' => $pendingRegistration->role,
                    'email_verified_at' => Carbon::now(), // Mark as verified immediately
                ]);

                // Delete the pending registration
                $pendingRegistration->delete();

                // Generate tokens
                $tokens = $this->generateTokensForUser($user);

                $message = 'Email verified successfully! Your account has been created.';
                if ($bypassEnabled) {
                    $message .= ' [Verified in DEV MODE]';
                }

                return [
                    'message' => $message,
                    'user' => $this->formatUserData($user),
                    'tokens' => $tokens,
                ];
            });

        } catch (\Exception $e) {
            throw new \Exception('Verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Resend verification code
     */
    public function resendVerificationCode(string $email): void
    {
        // Check if bypass mode is enabled
        $bypassEnabled = config('auth.verification.bypass_enabled', false);

        // Check if user already exists and is verified
        $existingUser = $this->userRepository->findByEmail($email);
        if ($existingUser && $existingUser->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already verified. Please login.']
            ]);
        }

        // Find pending registration
        $pendingRegistration = $this->pendingRegistrationRepository->findByEmail($email);

        if (!$pendingRegistration) {
            throw ValidationException::withMessages([
                'email' => ['No pending registration found for this email. Please register first.']
            ]);
        }

        // Check rate limiting
        $recentCount = $this->pendingRegistrationRepository->countByEmail($email);
        if ($recentCount >= 3) {
            throw ValidationException::withMessages([
                'email' => ['Too many verification attempts. Please try again later.']
            ]);
        }

        try {
            // Generate new code and update expiration
            $pendingRegistration->update([
                'code' => PendingRegistration::generateCode(),
                'expires_at' => Carbon::now()->addMinutes(
                    config('auth.verification.expire', 60)
                ),
            ]);

            // Resend email ONLY if bypass is disabled
            if (!$bypassEnabled) {
                Mail::to($pendingRegistration->email)->send(
                    new VerifyEmailMail($pendingRegistration)
                );
            }

        } catch (\Exception $e) {
            throw new \Exception('Failed to resend verification code: ' . $e->getMessage());
        }
    }

    /**
     * Login user with credentials
     */
    public function login(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (!$user) {
            // Check if there's a pending registration
            $pending = $this->pendingRegistrationRepository->findByEmail($credentials['email']);
            if ($pending) {
                throw ValidationException::withMessages([
                    'email' => ['Please verify your email first. Check your inbox for the verification code.']
                ]);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }

        if (!Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }

        // User exists and is verified (because they can only be created after verification)
        $tokens = $this->generateTokensForUser($user);

        return [
            'user' => $this->formatUserData($user),
            'tokens' => $tokens,
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshToken(string $refreshToken): array
    {
        $tokenRecord = $this->refreshTokenRepository->findByToken($refreshToken);

        if (!$tokenRecord || !$tokenRecord->isValid()) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired refresh token.']
            ]);
        }

        $user = $tokenRecord->user;
        $accessToken = JWTAuth::fromUser($user);

        $this->refreshTokenRepository->revoke($tokenRecord);
        $newRefreshToken = $this->createRefreshToken($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken->token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl', 60) * 60,
        ];
    }

    /**
     * Logout user (revoke tokens)
     */
    public function logout(string $refreshToken = null): void
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Exception $e) {
            // Token might already be invalid
        }

        if ($refreshToken) {
            $tokenRecord = $this->refreshTokenRepository->findByToken($refreshToken);
            if ($tokenRecord) {
                $this->refreshTokenRepository->revoke($tokenRecord);
            }
        }
    }

    /**
     * Logout from all devices
     */
    public function logoutFromAllDevices(int $userId): void
    {
        $this->refreshTokenRepository->revokeAllForUser($userId);
    }

    /**
     * Get authenticated user from JWT token
     */
    public function getAuthenticatedUser(): ?User
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate both access and refresh tokens for user
     */
    private function generateTokensForUser(User $user): array
    {
        $accessToken = JWTAuth::fromUser($user);
        $refreshToken = $this->createRefreshToken($user);
        $ttl = (int) config('jwt.ttl', 60);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken->token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
        ];
    }

    /**
     * Create a refresh token
     */
    private function createRefreshToken(User $user): \App\Models\RefreshToken
    {
        $refreshTtl = (int) config('jwt.refresh_ttl', 20160);

        return $this->refreshTokenRepository->create([
            'user_id' => $user->id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addMinutes($refreshTtl),
            'device_name' => request()->header('User-Agent'),
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Format user data for response
     */
    private function formatUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toDateTimeString(),
        ];
    }
}
