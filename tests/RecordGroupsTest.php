<?php

namespace Plugin\SiteMigration\Tests;

use App\Models\Address;
use App\Models\Comment;
use App\Models\Form;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\SiteMigration\Backend\Resources\Collision;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Plugin\SiteMigration\Backend\Runs\Run;
use Plugin\SiteMigration\Backend\Runs\RunStore;
use Plugin\SiteMigration\Backend\Services\Exporter;
use Plugin\SiteMigration\Backend\Services\Importer;

require_once __DIR__ . '/autoload.php';

/**
 * The record groups: orders, invoices, comments and leads — the resources that are about people.
 *
 * Three promises are pinned here, because each was the reason its driver did not exist earlier:
 *
 * 1. **A colliding invoice or order takes the destination's next number in sequence**, never a
 *    `-2` suffix — numbering is a sequence, not a label, and the renumbered record keeps the
 *    number it arrived under so the customer's copy can be matched to it.
 * 2. **Nested records travel with their parent and resolve by where things actually landed.** An
 *    order item whose product was renamed on the same run must point at the renamed product, not
 *    at whichever local record happens to hold the old SKU — the id-map seam exists for this.
 * 3. **A record that points at something absent is skipped and says so** — a comment about a post
 *    that is not here, a lead for a form that never travelled — rather than attached to the wrong
 *    thing or invented a home.
 */
class RecordGroupsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    #[Test]
    public function a_colliding_invoice_takes_the_next_number_in_sequence(): void
    {
        $number = 'INV-CLASH-' . bin2hex(random_bytes(3));

        $invoice = Invoice::create([
            'invoice_number' => $number,
            'status'         => Invoice::STATUS_SENT,
            'currency'       => 'MYR',
            'grand_total'    => 90,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'sku'        => 'HAT-1',
            'title'      => ['en' => 'A hat'],
            'qty'        => 2,
            'unit_price' => 45,
            'subtotal'   => 90,
        ]);

        $bundle = $this->export(['invoices']);

        // The site's own version now differs, so the incoming one collides for real.
        Invoice::where('invoice_number', $number)->first()->update(['grand_total' => 120]);

        $run = $this->importRun($bundle, ['invoices']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $tally = $this->reload($run)->tally();
        $this->assertSame(1, $tally['renamed'], 'The clashing invoice should have been renumbered.');
        $this->assertSame(0, $tally['failed']);

        $renamed = Invoice::query()
            ->where('meta->imported_number', $number)
            ->first();

        $this->assertNotNull($renamed, 'The renumbered invoice does not record the number it arrived under.');

        $this->assertMatchesRegularExpression(
            '/^INV-\d{6}$/',
            $renamed->invoice_number,
            'A renumbered invoice must take the destination sequence, not a suffix.'
        );

        $this->assertSame(
            1,
            $renamed->items()->count(),
            'The renumbered invoice arrived without its lines.'
        );

        $this->assertSame(
            '120.00',
            (string) Invoice::where('invoice_number', $number)->first()->grand_total,
            "This site's own invoice was modified despite overwriting being off."
        );
    }

    #[Test]
    public function an_order_travels_with_its_items_and_addresses(): void
    {
        $sku   = 'ORD-SKU-' . bin2hex(random_bytes(3));
        $email = 'buyer-' . bin2hex(random_bytes(3)) . '@example.test';

        $product  = Product::factory()->create(['sku' => $sku, 'productable_id' => null]);
        $customer = User::factory()->create(['email' => $email]);

        $order = $this->makeOrder($product, $customer);

        $placedAt = $order->created_at->toDateTimeString();

        $bundle = $this->export(['orders']);

        // Gone entirely, so the import has to rebuild the order, its line and its address.
        OrderItem::where('order_id', $order->id)->delete();
        Address::where('addressable_type', Order::class)->where('addressable_id', $order->id)->delete();
        $order->forceDelete();

        $run = $this->importRun($bundle, ['orders']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(0, $this->reload($run)->tally()['failed']);

        $imported = Order::where('order_number', $order->order_number)->first();

        $this->assertNotNull($imported, 'The order did not come back.');
        $this->assertSame($email, $imported->customer?->email, 'The customer was not resolved by email.');
        $this->assertSame($placedAt, $imported->created_at->toDateTimeString(), 'The order lost the date it was placed.');

        $item = $imported->items()->first();
        $this->assertNotNull($item, 'The order arrived without its line.');
        $this->assertSame($sku, $item->sku);
        $this->assertSame($product->id, $item->product_id, 'The line was not linked back to its product by SKU.');

        $billing = $imported->billingAddress;
        $this->assertNotNull($billing, 'The order arrived without its billing address.');
        $this->assertSame('Jane', $billing->first_name);
        $this->assertSame(Order::class, $billing->addressable_type, 'The address must attach to the order, never an address book.');

        // Idempotence: the same bundle again writes nothing new.
        $again = $this->importRun($bundle, ['orders']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($again)));

        $this->assertSame(1, Order::where('order_number', $order->order_number)->count());
        $this->assertSame(0, $this->reload($again)->tally()['created'], 'A second import should create nothing.');
    }

    /**
     * The reason the id map had to reach the drivers at all: an order whose product was renamed
     * on the same run must link to the renamed product — where the source's record actually
     * landed — not to whichever local record still holds the old SKU.
     */
    #[Test]
    public function an_order_item_follows_its_product_through_a_rename(): void
    {
        $sku = 'MAP-SKU-' . bin2hex(random_bytes(3));

        $product = Product::factory()->create([
            'sku'            => $sku,
            'title'          => ['en' => 'From the bundle'],
            'productable_id' => null,
        ]);

        $order = $this->makeOrder($product, null);

        $bundle = $this->export(['products', 'orders']);

        // The local product now differs, so the incoming one is renamed alongside it —
        // and the local order is gone, so the incoming order is created fresh.
        Product::where('sku', $sku)->first()->update(['title' => ['en' => 'Mine, edited here']]);
        OrderItem::where('order_id', $order->id)->delete();
        $order->forceDelete();

        $run = $this->importRun($bundle, ['products', 'orders']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(0, $this->reload($run)->tally()['failed']);

        $renamedProduct = Product::where('sku', $sku . '-2')->first();
        $this->assertNotNull($renamedProduct, 'The clashing product was not kept alongside.');

        $item = Order::where('order_number', $order->order_number)->first()?->items()->first();

        $this->assertNotNull($item, 'The order did not come back with its line.');
        $this->assertSame(
            $renamedProduct->id,
            $item->product_id,
            'The order item points at the local product that kept the old SKU, not at the renamed '
            . 'product this run actually placed — the id map was not consulted.'
        );
    }

    #[Test]
    public function a_comment_thread_lands_on_its_target_and_keeps_its_threading(): void
    {
        $product = Product::factory()->create([
            'sku'            => 'CMT-SKU-' . bin2hex(random_bytes(3)),
            'productable_id' => null,
        ]);

        $parent = Comment::create([
            'body'             => 'A parent comment from the source site.',
            'status'           => 'active',
            'commentable_type' => Product::class,
            'commentable_id'   => $product->id,
        ]);

        $reply = Comment::create([
            'body'             => 'A reply that must keep its parent.',
            'status'           => 'active',
            'commentable_type' => Product::class,
            'commentable_id'   => $product->id,
            'parent_id'        => $parent->id,
        ]);

        $saidAt = $parent->created_at->toDateTimeString();

        $bundle = $this->export(['comments']);

        $reply->forceDelete();
        $parent->forceDelete();

        $run = $this->importRun($bundle, ['comments']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(0, $this->reload($run)->tally()['failed']);

        $landedParent = Comment::where('body', 'A parent comment from the source site.')->first();
        $landedReply  = Comment::where('body', 'A reply that must keep its parent.')->first();

        $this->assertNotNull($landedParent, 'The parent comment did not come back.');
        $this->assertNotNull($landedReply, 'The reply did not come back.');

        $this->assertSame($product->id, $landedParent->commentable_id, 'The comment lost its target.');
        $this->assertSame($landedParent->id, $landedReply->parent_id, 'The reply lost its threading.');
        $this->assertSame($saidAt, $landedParent->created_at->toDateTimeString(), 'The comment lost the moment it was said.');
    }

    #[Test]
    public function a_comment_whose_target_is_missing_is_skipped_and_says_so(): void
    {
        $product = Product::factory()->create([
            'sku'            => 'GONE-SKU-' . bin2hex(random_bytes(3)),
            'productable_id' => null,
        ]);

        Comment::create([
            'body'             => 'A comment about something that will not travel.',
            'status'           => 'active',
            'commentable_type' => Product::class,
            'commentable_id'   => $product->id,
        ]);

        $bundle = $this->export(['comments']);

        Comment::query()->forceDelete();
        $product->forceDelete();

        $run = $this->importRun($bundle, ['comments']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $tally = $this->reload($run)->tally();

        $this->assertSame(0, $tally['failed'], 'An unplaceable comment must be skipped, not failed.');

        $this->assertSame(
            0,
            Comment::where('body', 'A comment about something that will not travel.')->count(),
            'A comment with no target here was written anyway — it was invented a home.'
        );

        $this->assertStringContainsString(
            'not on this site',
            implode(' ', (array) $this->reload($run)->get('errors', [])),
            'The skip must say why, or the operator reads a clean tally over a missing comment.'
        );
    }

    #[Test]
    public function a_lead_lands_on_its_form_and_a_second_import_writes_nothing(): void
    {
        $form = Form::factory()->create();

        $lead = Lead::create([
            'form_id'    => $form->id,
            'data'       => ['name' => 'Jane Doe', 'message' => 'Hello from the source site.'],
            'ip_address' => '203.0.113.9',
            'user_agent' => 'ExampleBrowser/1.0',
        ]);

        $submittedAt = $lead->created_at->toDateTimeString();

        $bundle = $this->export(['leads']);

        $lead->delete();

        $run = $this->importRun($bundle, ['leads']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($run)));

        $this->assertSame(0, $this->reload($run)->tally()['failed']);

        $landed = Lead::where('form_id', $form->id)->first();

        $this->assertNotNull($landed, 'The lead did not come back.');
        $this->assertSame('Jane Doe', $landed->data['name'] ?? null);
        $this->assertSame('203.0.113.9', $landed->ip_address);
        $this->assertSame($submittedAt, $landed->created_at->toDateTimeString(), 'The lead lost the moment it was submitted.');

        $again = $this->importRun($bundle, ['leads']);
        $this->pressUntilDone(fn () => app(Importer::class)->step($this->reload($again)));

        $this->assertSame(1, Lead::where('form_id', $form->id)->count(), 'A second import duplicated the submission.');
        $this->assertSame(0, $this->reload($again)->tally()['created'], 'A second import should create nothing.');
    }

    /**
     * Every new driver describes a **rich** record identically eager-loaded and bare.
     *
     * The shared `DriverSymmetryTest` runs this same comparison, but only over rows the test
     * database happens to hold — and it holds no orders, invoices, comments or leads, so the
     * four newest drivers would go unchecked by the one test that catches the worst silent
     * failure this package knows (a page once exported its whole builder tree and compared
     * against an empty one, rewriting every page on every migration, forever). This builds one
     * record per driver with everything loaded — items, addresses, a linked order, a template
     * id in the meta, a threaded reply — and holds the drivers to the same promise.
     */
    #[Test]
    public function every_record_driver_describes_a_rich_record_identically_twice(): void
    {
        $sku   = 'SYM-SKU-' . bin2hex(random_bytes(3));
        $email = 'sym-' . bin2hex(random_bytes(3)) . '@example.test';

        $product  = Product::factory()->create(['sku' => $sku, 'productable_id' => null]);
        $customer = User::factory()->create(['email' => $email]);

        $order = $this->makeOrder($product, $customer);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-SYM-' . bin2hex(random_bytes(3)),
            'order_id'       => $order->id,
            'status'         => Invoice::STATUS_PAID,
            'currency'       => 'MYR',
            'issued_at'      => now()->subDay(),
            'due_at'         => now()->addWeek(),
            'grand_total'    => 45,
            'meta'           => ['template_id' => 999, 'tax_lines' => [['label' => 'SST', 'amount' => '2.70']]],
            'user_id'        => $customer->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'sku'        => $sku,
            'title'      => ['en' => 'A hat'],
            'qty'        => 1,
            'unit_price' => 45,
            'subtotal'   => 45,
        ]);

        $parent = Comment::create([
            'body'             => 'Symmetry parent.',
            'status'           => 'active',
            'commentable_type' => Product::class,
            'commentable_id'   => $product->id,
            'user_id'          => $customer->id,
        ]);

        Comment::create([
            'body'             => 'Symmetry reply.',
            'status'           => 'active',
            'commentable_type' => Product::class,
            'commentable_id'   => $product->id,
            'parent_id'        => $parent->id,
        ]);

        Lead::create([
            'form_id' => Form::factory()->create()->id,
            'data'    => ['name' => 'Sym', 'nested' => ['z' => 1, 'a' => 2]],
        ]);

        $registry = app(DriverRegistry::class);

        foreach (['orders', 'invoices', 'comments', 'leads'] as $key) {
            $driver = $registry->for($key);

            foreach ($driver->exportQuery()->get() as $eager) {
                $exported = $driver->toRecord($eager);
                $located  = $driver->locate($exported);

                $this->assertNotNull(
                    $located,
                    "[{$key}] a record this driver just exported could not be found again by its "
                    . 'own identity, so every migration would duplicate it.'
                );

                $this->assertSame(
                    \Plugin\SiteMigration\Backend\Support\Canonical::encode($exported, $driver->volatileFields()),
                    \Plugin\SiteMigration\Backend\Support\Canonical::encode($driver->toRecord($located), $driver->volatileFields()),
                    "[{$key}] the driver describes one record two different ways depending on how "
                    . 'it was loaded — every such record would be rewritten on every migration.'
                );
            }
        }
    }

    /** The five resources about people are record groups, and none of them is content. */
    #[Test]
    public function every_resource_about_people_is_a_record_group(): void
    {
        $registry = app(DriverRegistry::class);

        foreach (['users', 'orders', 'invoices', 'comments', 'leads'] as $key) {
            $this->assertContains($key, $registry->recordKeys(), "{$key} must be a record group.");
            $this->assertNotContains($key, $registry->contentKeys(), "{$key} must not be part of the content list.");
        }
    }

    // ---------------------------------------------------------------- helpers

    private function makeOrder(Product $product, ?User $customer): Order
    {
        $order = Order::create([
            'order_number'   => 'ORD-TEST-' . bin2hex(random_bytes(3)),
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'currency'       => 'MYR',
            'subtotal'       => 45,
            'grand_total'    => 45,
            'user_id'        => $customer?->id,
        ]);

        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'sku'        => $product->sku,
            'title'      => ['en' => 'A hat'],
            'qty'        => 1,
            'unit_price' => 45,
            'subtotal'   => 45,
        ]);

        $billing = new Address([
            'type'           => 'Home',
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'email'          => $customer?->email,
            'address_line_1' => '1 Example Street',
            'city'           => 'Kuala Lumpur',
            'country'        => 'MY',
            'is_billing'     => true,
        ]);

        $billing->addressable_type = Order::class;
        $billing->addressable_id   = $order->id;
        $billing->save();

        $order->billing_address_id = $billing->id;
        $order->save();

        return $order->fresh();
    }

    /** @param array<int,string> $modules */
    private function export(array $modules): string
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_EXPORT, [
            'modules'       => $modules,
            'include_media' => false,
        ]);

        $this->pressUntilDone(fn () => app(Exporter::class)->step($this->reload($run)));

        $bundle = $this->reload($run)->bundlePath();

        $this->assertNotNull($bundle, 'The export did not seal a bundle.');

        return $bundle;
    }

    /** @param array<int,string> $modules */
    private function importRun(string $bundle, array $modules): Run
    {
        $run = app(RunStore::class)->create(Run::DIRECTION_IMPORT, [
            'modules'                => $modules,
            'on_conflict'            => Collision::KEEP_BOTH,
            'overwrite_acknowledged' => false,
        ]);

        copy($bundle, $run->directory . '/bundle.zip');

        return $run;
    }

    private function reload(Run $run): Run
    {
        return app(RunStore::class)->find($run->id);
    }
}
