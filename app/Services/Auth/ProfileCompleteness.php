<?php
namespace App\Services\Auth;

use App\Models\User;

final class ProfileCompleteness
{
    public function for(User $user): array
    {
        $missing = [];
        if (!filled($user->first_name)) $missing[] = 'first_name';
        if (!filled($user->last_name)) $missing[] = 'last_name';
        if (!$user->phone_verified_at) $missing[] = 'mobile';
        return [
            'profile_complete' => $missing === [],
            'missing_profile_fields' => $missing,
            'mobile_verified' => (bool) $user->phone_verified_at,
            'has_password' => filled($user->password),
        ];
    }

    public function assertComplete(User $user): void
    {
        $state = $this->for($user);
        if (!$state['profile_complete']) {
            throw AuthFlowException::make(422, 'PROFILE_INCOMPLETE', 'برای رزرو نوبت، اطلاعات حساب کاربری خود را تکمیل کنید.', [
                'missing_profile_fields' => $state['missing_profile_fields'],
            ]);
        }
    }
}
