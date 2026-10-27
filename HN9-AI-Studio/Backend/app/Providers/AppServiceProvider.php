<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureTokenAuthentication();
    }

    /**
     * Named limiters for abuse-prone endpoints (M12). Limiter keys never hold a
     * raw credential: the email is hashed and tokens are never used as keys.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? Str::lower(trim($email)) : '';
            $ip = (string) $request->ip();
            $response = self::throttled('Too many login attempts. Please wait a minute and try again.', 'auth.throttled');

            return [
                Limit::perMinute(5)->by('login:'.hash('sha256', $email.'|'.$ip))->response($response),
                Limit::perMinute(20)->by('login-ip:'.$ip)->response($response),
            ];
        });

        RateLimiter::for('password', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password:'.self::actorKey($request))
            ->response(self::throttled('Too many password change attempts. Please wait a minute and try again.', 'auth.throttled')));

        RateLimiter::for('generation', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('generation:'.self::actorKey($request))
            ->response(self::throttled('Too many generation requests. Please wait a moment and try again.', 'rate_limited')));
    }

    /**
     * Sanctum already rejects expired and deleted tokens. Also reject a token
     * whose owner is no longer active, so disabling an account takes effect on
     * the very next request.
     */
    private function configureTokenAuthentication(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            static fn (PersonalAccessToken $accessToken, bool $isValid): bool => $isValid
                && (! $accessToken->tokenable instanceof User || $accessToken->tokenable->isActive())
        );
    }

    private static function actorKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /**
     * @return Closure(Request, array<string, string>): JsonResponse
     */
    private static function throttled(string $message, string $errorCode): Closure
    {
        return static fn (Request $request, array $headers): JsonResponse => ApiResponse::error($message, $errorCode, 429)
            ->withHeaders($headers);
    }
}
