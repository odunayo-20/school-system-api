<?php

namespace App\Services\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Owns every credential check and every token operation. Controllers stay thin and
 * never touch Auth::attempt() or the personal access token table directly.
 */
class AuthenticationService
{
    /**
     * Verify credentials, refuse the account if it is not ACTIVE or has no role, then
     * issue an API token.
     *
     * The same exception is thrown for an unknown email, a wrong password, an
     * inactive account and a suspended account, so the endpoint cannot be used to
     * enumerate accounts.
     *
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws AuthenticationException
     */
    public function authenticate(array $credentials, string $tokenName = 'api'): NewAccessToken
    {
        // Resolve the provider directly rather than going through a guard, so no
        // session is ever started for a stateless API request.
        $provider = Auth::createUserProvider('users');

        $user = $provider->retrieveByCredentials(['email' => $credentials['email']]);
        $status = null;

        if ($user instanceof User && $provider->validateCredentials($user, $credentials)) {
            $status = $user->status;

            if ($user->isActive() && $user->roleEnum() !== null) {
                $user->recordLogin();

                return $user->createToken($tokenName);
            }
        }

        $this->logFailedAttempt($credentials['email'], $status);

        throw new AuthenticationException('The provided credentials are incorrect.');
    }

    /**
     * Invalidate the credential used to make this request.
     */
    public function logout(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        // No API token on the request (e.g. a session authenticated request): clear
        // every token the account holds so logging out is still effective.
        $user->tokens()->delete();
    }

    /**
     * Revoke every token held by a user, e.g. after a password change.
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Never log the submitted password: only the address and the account status.
     */
    protected function logFailedAttempt(string $email, ?UserStatus $status = null): void
    {
        Log::notice('Failed login attempt.', [
            'email' => $email,
            'account_status' => $status?->value,
        ]);
    }
}
