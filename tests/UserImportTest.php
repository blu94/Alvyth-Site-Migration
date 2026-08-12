<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\UserDriver;

require_once __DIR__ . '/autoload.php';

/**
 * Accounts arrive without the ability to sign in.
 *
 * Ovynt hashes with bcrypt, so a carried hash *would* verify on the destination — which is exactly
 * why none is carried. Silently granting a login on a second site to everyone who had one on the
 * first is a security decision the operator was never asked to make, and cannot undo once made.
 */
class UserImportTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function no_password_or_second_factor_leaves_the_site(): void
    {
        $this->actingAsSuperAdmin();

        $user = User::factory()->create([
            'email'    => 'carrier-' . bin2hex(random_bytes(3)) . '@example.test',
            'password' => Hash::make('the-original-password'),
        ]);

        $record = app(UserDriver::class)->toRecord($user->fresh());

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $record, "A bundle must never carry {$forbidden}.");
        }

        $this->assertStringNotContainsString(
            'the-original-password',
            json_encode($record),
            'The password reached the bundle by some other route.'
        );
    }

    /** An imported account cannot be signed into with the password it had on the source. */
    #[Test]
    public function an_imported_account_cannot_be_signed_into(): void
    {
        $this->actingAsSuperAdmin();

        $email = 'imported-' . bin2hex(random_bytes(3)) . '@example.test';

        $written = app(UserDriver::class)->write([
            'email' => $email,
            'name'  => 'Imported Person',
        ], null);

        $this->assertFalse(
            Hash::check('the-original-password', $written->password),
            'The source password works on the destination, which is the exact thing this must not do.'
        );

        $this->assertFalse(Hash::check('', $written->password));
        $this->assertNotEmpty($written->password, 'An empty password is a value something could match.');
    }

    /**
     * An imported account arrives with no role.
     *
     * A role is an authorisation decision about *this* install. Carrying one would let a bundle
     * grant administrator rights on the destination.
     */
    #[Test]
    public function an_imported_account_carries_no_role(): void
    {
        $this->actingAsSuperAdmin();

        $written = app(UserDriver::class)->write([
            'email' => 'roleless-' . bin2hex(random_bytes(3)) . '@example.test',
            'name'  => 'No Role',
        ], null);

        $this->assertCount(0, $written->fresh()->roles, 'A bundle granted a role on this install.');
    }

    /**
     * Two shops both having a `jane` is the normal case, not an edge one.
     *
     * `username` is what route model binding resolves a user by, so a collision would either fail
     * the write or — worse — resolve one person's URL to another's account.
     */
    #[Test]
    public function a_colliding_username_is_given_a_distinct_one(): void
    {
        $this->actingAsSuperAdmin();

        $taken = 'jane' . bin2hex(random_bytes(3));

        User::factory()->create(['username' => $taken, 'email' => $taken . '@here.test']);

        $written = app(UserDriver::class)->write([
            'email'    => $taken . '@elsewhere.test',
            'name'     => 'Jane From Elsewhere',
            'username' => $taken,
        ], null);

        $this->assertNotSame($taken, $written->username);
        $this->assertSame(1, User::query()->where('username', $written->username)->count());
    }

    /** An account already here keeps its own username rather than being renamed by a bundle. */
    #[Test]
    public function an_existing_account_keeps_its_username(): void
    {
        $this->actingAsSuperAdmin();

        $existing = User::factory()->create([
            'username' => 'settled' . bin2hex(random_bytes(3)),
            'email'    => 'settled-' . bin2hex(random_bytes(3)) . '@example.test',
        ]);

        $written = app(UserDriver::class)->write([
            'email'    => $existing->email,
            'name'     => 'Renamed By Bundle',
            'username' => 'something-else',
        ], $existing);

        $this->assertSame($existing->username, $written->fresh()->username);
    }
}
