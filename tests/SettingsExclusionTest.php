<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Meta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\SettingsDriver;

require_once __DIR__ . '/autoload.php';

/**
 * The settings groups that must never travel, asserted against the driver rather than the screen.
 *
 * **These are refusals in code, not defaults on a form.** The difference is the whole point: a
 * group left off a default selection can be ticked, and a group refused here cannot travel however
 * hard anyone tries — including through a hand-edited bundle arriving at the import.
 */
class SettingsExclusionTest extends TestCase
{
    use DatabaseTransactions;

    /** Payment, mail and AI settings are not among the groups the driver will walk. */
    #[Test]
    public function payment_mail_and_ai_are_never_exported(): void
    {
        $this->actingAsSuperAdmin();

        foreach (SettingsDriver::NEVER as $type) {
            Meta::query()->firstOrCreate(['type' => $type], ['title' => $type, 'data' => ['leak' => 'zzz-secret-zzz']]);
        }

        $types = app(SettingsDriver::class)->exportQuery()->pluck('type')->all();

        foreach (SettingsDriver::NEVER as $type) {
            $this->assertNotContains(
                $type,
                $types,
                "{$type} is reachable by the settings export. It contains credentials and must not be."
            );
        }
    }

    /**
     * The e-invoice profile travels, but its credential fields do not.
     *
     * Unlike payment and mail, most of this group is a *seller identity* — TIN, BRN, address —
     * which is genuinely the same configuration on a rebuilt site. The keys that let a machine
     * submit as that seller are the part that must not go.
     */
    #[Test]
    public function einvoice_travels_without_its_credentials(): void
    {
        $this->actingAsSuperAdmin();

        $meta = Meta::query()->updateOrCreate(['type' => 'EINVOICE_SETTING'], [
            'title' => 'e-Invoice',
            'data'  => [
                'tin'                   => 'C1234567890',
                'brn'                   => '201901000001',
                'address'               => '1 Example Road',
                'client_id'             => 'ZZZ-CLIENT-ID',
                'client_secret'         => 'ZZZ-CLIENT-SECRET',
                'myinvois_client_secret' => 'ZZZ-PREFIXED-SECRET',
            ],
        ]);

        $record = app(SettingsDriver::class)->toRecord($meta->fresh());
        $values = $record['values'];

        $this->assertSame('C1234567890', $values['tin'] ?? null, 'The seller identity should travel.');
        $this->assertSame('1 Example Road', $values['address'] ?? null);

        $this->assertArrayNotHasKey('client_id', $values);
        $this->assertArrayNotHasKey('client_secret', $values);

        // Matched on *containing* a credential word rather than equalling one, because these blobs
        // grow prefixed variants and an exact-match list leaks the next one somebody adds.
        $this->assertArrayNotHasKey(
            'myinvois_client_secret',
            $values,
            'A prefixed credential key escaped the filter, which is how the next one will too.'
        );
    }

    /**
     * A hand-edited bundle cannot write a refused group *into* this install.
     *
     * The same filter runs on the way in as on the way out, so an attacker who crafts a bundle
     * claiming to carry mail settings gets nothing written rather than a path into `metas` that
     * looks like ordinary configuration.
     */
    #[Test]
    public function a_refused_group_cannot_be_imported_either(): void
    {
        $this->actingAsSuperAdmin();

        $driver = app(SettingsDriver::class);

        $this->expectExceptionMessageMatches('/not a settings group/i');

        $driver->write(['group' => 'MAIL_SETTING', 'values' => ['password' => 'zzz']], null);
    }
}
