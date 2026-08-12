<?php

namespace Plugin\SiteMigration\Backend\Resources;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Customer and staff accounts. **Opt-in, off by default, and warned about on screen.**
 *
 * These are records *about people*, not content the operator authored, so they fail the admission
 * test every other resource passes. Moving them is occasionally right — a genuine host migration —
 * and usually wrong, as when seeding a new shop from a template. The switch defaults off for that
 * reason, and the screen states the hazard rather than burying it in a manual: **a bundle with
 * accounts in it is a personal-data export the moment it leaves the building.**
 *
 * **Passwords travel or they do not, and both are wrong.** Ovynt hashes with bcrypt, so a carried
 * hash *would* verify on the destination — which is worse than it sounds. Silently granting a
 * login on a second site to everyone who had one on the first is a security decision the operator
 * has not been asked to make, and one they cannot undo once made. So **users import with an
 * unusable password**: a random 64-character secret nobody holds, which no login can match and
 * which forces the ordinary password-reset flow. The account, its name and its history arrive; the
 * ability to sign in does not.
 *
 * **Nothing authentication-adjacent travels at all.** Not `remember_token`, not
 * `two_factor_secret` or `two_factor_recovery_codes` — those are `encrypted` casts bound to the
 * source's `APP_KEY` and `$hidden` besides — and not sessions or personal access tokens, which are
 * separate tables and are simply never read. A transferred session token is a transferred login.
 *
 * **Roles do not travel either.** A role is an authorisation decision about *this* install, and
 * importing one would let a bundle grant administrator rights on the destination. Imported
 * accounts arrive with no role, and somebody with the right to grant one has to do so deliberately.
 */
class UserDriver extends BaseDriver
{
    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Customer accounts';
    }

    public function naturalKey(): string
    {
        return 'email';
    }

    public function exportQuery(): Builder
    {
        return User::query()->orderBy('id');
    }

    /**
     * @param  User  $record
     * @return array<string,mixed>
     */
    public function toRecord(Model $record): array
    {
        return [
            '_source'  => $record->getKey(),
            'email'    => $record->email,
            'name'     => $record->name,
            'username' => $record->username,
            'status'   => $record->status,

            // Deliberately absent, and the omission is the feature: `password`,
            // `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`. There is no
            // switch anywhere that adds them.
        ];
    }

    /** @param array<string,mixed> $record */
    public function locate(array $record): ?Model
    {
        $email = $this->requireKey($record, 'email', 'account');

        return User::query()->where('email', $email)->first();
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  User|null  $existing
     */
    public function write(array $record, ?Model $existing): Model
    {
        $email = $this->requireKey($record, 'email', 'account');

        $user = $existing ?? new User();

        $user->fill([
            'name'     => $record['name'] ?? $email,
            'email'    => $email,
            'username' => $this->username($record, $email, $existing),
            'status'   => $record['status'] ?? 'active',
        ]);

        if ($existing === null) {
            // A secret nobody has, including this process once the request ends. Not an empty
            // string and not a null: both are values some future comparison could match, whereas
            // a bcrypt hash of 64 random bytes cannot be produced by any password a person types.
            $user->password = Hash::make(Str::random(64));
        }

        $user->save();

        return $user;
    }

    /**
     * A username that will not collide.
     *
     * `username` is what route model binding resolves a user by, so it has to be unique — and two
     * shops legitimately both having a `admin` or a `jane` is the normal case, not an edge one.
     * An existing account keeps the username it already has here.
     *
     * @param  array<string,mixed>  $record
     */
    private function username(array $record, string $email, ?Model $existing): string
    {
        if ($existing !== null && ! empty($existing->username)) {
            return (string) $existing->username;
        }

        $wanted = (string) ($record['username'] ?? Str::before($email, '@'));
        $base   = Str::slug($wanted) ?: 'user';
        $try    = $base;
        $n      = 1;

        while (User::query()->where('username', $try)->exists()) {
            $try = $base . '-' . (++$n);
        }

        return $try;
    }

    /**
     * `status` is local: an account this site suspended must not be reactivated by a bundle that
     * happens to predate the suspension.
     */
    public function volatileFields(): array
    {
        return array_merge(parent::volatileFields(), ['status', 'username']);
    }
}
