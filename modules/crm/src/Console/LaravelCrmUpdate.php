<?php

namespace VentureDrake\LaravelCrm\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\QuoteProduct;
use VentureDrake\LaravelCrm\Services\NumberGeneratorService;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrm\Support\BusinessContext;

/**
 * The half of the update that touches the database.
 *
 * Split out from laravelcrm:upgrade — which republishes assets and clears
 * caches, runs from the host's composer hook, and never opens a connection.
 * This command runs migrations, seeders and data backfills, so it stays
 * explicit: an operator (or a deploy script) runs it, once, after the code is
 * in place.
 *
 * It calls laravelcrm:upgrade first, so a human running one command by hand
 * still gets everything.
 *
 * Every failure here is fatal. This command used to catch migration and seeder
 * exceptions, downgrade them to warnings and still print "Laravel CRM is now
 * updated", which made a broken upgrade indistinguishable from a clean one in
 * a deploy log — and left the deploy script's `&&` chain running happily on.
 */
class LaravelCrmUpdate extends Command
{
    /**
     * The setting the database schema version is stamped into on success.
     *
     * This marker only moves when the installation command completes, so
     * "code is ahead of database" remains detectable after a partial deploy.
     */
    public const DB_VERSION_SETTING = 'db_version';

    /**
     * @var SettingService
     */
    private $settingService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravelcrm:update
                           {--force : Run without confirmation, for deploy scripts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply Laravel CRM database migrations, seed data and backfills';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(SettingService $settingService)
    {
        parent::__construct();
        $this->settingService = $settingService;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return self::SUCCESS;
        }

        $this->info('Updating Laravel CRM...');

        // The safe half: republishes assets, prunes stale build output and
        // clears cached config/routes/views. Runs first so any stale
        // `config:cache` from the host can't poison reads of freshly-published
        // config or env below, and so an operator running this one command by
        // hand gets the whole upgrade.
        if ($this->call('laravelcrm:upgrade') !== self::SUCCESS) {
            $this->error('laravelcrm:upgrade failed. Laravel CRM has NOT been updated.');

            return self::FAILURE;
        }

        $this->info('Running migrations...');

        // Fatal, not a warning. A failed ALTER leaves the schema behind the
        // code, and every backfill below reads that schema — carrying on would
        // report success over a half-applied upgrade.
        try {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                $this->error('Migrations failed. Laravel CRM has NOT been updated.');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Migrations failed: '.$e->getMessage());
            $this->error('Laravel CRM has NOT been updated.');

            return self::FAILURE;
        }

        $this->info('Reseeding base tables...');

        try {
            if ($this->callSilent('db:seed', [
                '--class' => 'VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder',
                '--force' => true,
            ]) !== self::SUCCESS) {
                $this->error('Seeding base tables failed. Laravel CRM has NOT been updated.');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Seeding base tables failed: '.$e->getMessage());
            $this->error('Laravel CRM has NOT been updated.');

            return self::FAILURE;
        }

        $this->seedLookupData();

        // Historical data migrations are an explicit installation operation.
        // Ordinary console reads remain tenant-scoped and fail closed.
        return app(BusinessContext::class)->system(fn () => DB::transaction(fn () => $this->applyLegacyBackfills()));
    }

    protected function applyLegacyBackfills(): int
    {
        if ($this->settingService->getInstallWide('db_update_0180') == 0) {
            $this->info('Updating Laravel CRM quote numbers...');

            foreach (Quote::whereNull('number')->get() as $quote) {
                $this->info('Updating Laravel CRM quote #'.$quote->id);

                $this->backfillNumber($quote, 'quote');
            }

            $this->info('Updating Laravel CRM quote numbers complete');

            $this->info('Updating Laravel CRM order numbers...');

            foreach (Order::whereNull('number')->get() as $order) {
                $this->info('Updating Laravel CRM order #'.$order->id);

                $this->backfillNumber($order, 'order');
            }

            $this->settingService->setInstallWide('db_update_0180', 1);
            $this->info('Updating Laravel CRM orders numbers complete');
        }

        if ($this->settingService->getInstallWide('db_update_0181') == 0) {
            $this->info('Updating Laravel CRM organization linked to person...');

            foreach (Person::whereNotNull('organization_id')->get() as $person) {
                if ($contact = $person->contacts()->create([
                    'team_id' => $person->team_id,
                    'entityable_type' => $person->organization->getMorphClass(),
                    'entityable_id' => $person->organization->id,
                ])) {
                    $person->update([
                        'organization_id' => null,
                    ]);
                }
            }

            $this->settingService->setInstallWide('db_update_0181', 1);
            $this->info('Updating Laravel CRM organization linked to person complete.');
        }

        if ($this->settingService->getInstallWide('db_update_0191') == 0) {
            $this->info('Updating Laravel CRM split orders, invoices & deliveries...');

            foreach (Order::whereNotNull('quote_id')->get() as $order) {
                if ($order->quote) {
                    foreach ($order->quote->quoteProducts as $quoteProduct) {
                        if ($orderProduct = $order->orderProducts()
                            ->whereNull('quote_product_id')
                            ->where([
                                'product_id' => $quoteProduct->product_id,
                                'price' => $quoteProduct->price,
                            ])->first()) {
                            $orderProduct->update([
                                'quote_product_id' => $quoteProduct->id,
                            ]);
                        }
                    }
                }
            }

            foreach (Invoice::whereNotNull('order_id')->get() as $invoice) {
                if ($invoice->order) {
                    foreach ($invoice->order->orderProducts as $orderProduct) {
                        if ($invoiceLine = $invoice->invoiceLines()
                            ->whereNull('order_product_id')
                            ->where([
                                'product_id' => $orderProduct->product_id,
                                'price' => $orderProduct->price,
                            ])->first()) {
                            $invoiceLine->update([
                                'order_product_id' => $orderProduct->id,
                            ]);
                        }
                    }
                }
            }

            $this->settingService->setInstallWide('db_update_0191', 1);
            $this->info('Updating Laravel CRM split orders, invoices & deliveries complete.');
        }

        if ($this->settingService->getInstallWide('db_update_0193') == 0) {
            $this->info('Updating Laravel CRM split deliveries...');

            foreach (Delivery::whereNotNull('order_id')->get() as $delivery) {
                if ($delivery->order) {
                    foreach ($delivery->order->orderProducts as $orderProduct) {
                        if ($deliveryProduct = $delivery->deliveryProducts()
                            ->whereNull('quantity')
                            ->where([
                                'order_product_id' => $orderProduct->id,
                            ])->first()) {
                            $deliveryProduct->update([
                                'quantity' => $orderProduct->quantity,
                            ]);
                        }
                    }
                }
            }

            $this->settingService->setInstallWide('db_update_0193', 1);
            $this->info('Updating Laravel CRM split deliveries complete.');
        }

        if ($this->settingService->getInstallWide('db_update_0194') == 0) {
            $this->info('Updating Laravel CRM delivery numbers...');

            foreach (Delivery::whereNull('number')->get() as $delivery) {
                $this->info('Updating Laravel CRM delivery #'.$delivery->id);

                $this->backfillNumber($delivery, 'delivery');
            }

            $this->settingService->setInstallWide('db_update_0194', 1);
            $this->info('Updating Laravel CRM delivery numbers complete');
        }

        if ($this->settingService->getInstallWide('db_update_0199') == 0) {
            $this->info('Updating Laravel CRM tax amounts...');

            foreach (QuoteProduct::whereNull('tax_amount')->get() as $quoteProduct) {
                $this->info('Updating Laravel CRM quote product tax #'.$quoteProduct->id);

                if ($quoteProduct->product && $quoteProduct->product->taxRate) {
                    $taxRate = $quoteProduct->product->taxRate->rate;
                } elseif ($quoteProduct->product && $quoteProduct->product->tax_rate) {
                    $taxRate = $quoteProduct->product->tax_rate;
                } else {
                    $taxRate = app(BusinessContext::class)->run($quoteProduct->team_id, fn () => $this->settingService->get('tax_rate', 0));
                }

                $quoteProduct->update([
                    'tax_rate' => $taxRate,
                    'tax_amount' => $quoteProduct->amount * ($taxRate / 100),
                ]);
            }

            foreach (OrderProduct::whereNull('tax_amount')->get() as $orderProduct) {
                $this->info('Updating Laravel CRM order product tax #'.$orderProduct->id);

                if ($orderProduct->product && $orderProduct->product->taxRate) {
                    $taxRate = $orderProduct->product->taxRate->rate;
                } elseif ($orderProduct->product && $orderProduct->product->tax_rate) {
                    $taxRate = $orderProduct->product->tax_rate;
                } else {
                    $taxRate = app(BusinessContext::class)->run($orderProduct->team_id, fn () => $this->settingService->get('tax_rate', 0));
                }

                $orderProduct->update([
                    'tax_rate' => $taxRate,
                    'tax_amount' => $orderProduct->amount * ($taxRate / 100),
                ]);
            }

            foreach (InvoiceLine::whereNull('tax_amount')->get() as $invoiceLine) {
                $this->info('Updating Laravel CRM invoice line tax #'.$invoiceLine->id);

                if ($invoiceLine->product && $invoiceLine->product->taxRate) {
                    $taxRate = $invoiceLine->product->taxRate->rate;
                } elseif ($invoiceLine->product && $invoiceLine->product->tax_rate) {
                    $taxRate = $invoiceLine->product->tax_rate;
                } else {
                    $taxRate = app(BusinessContext::class)->run($invoiceLine->team_id, fn () => $this->settingService->get('tax_rate', 0));
                }

                $invoiceLine->update([
                    'tax_rate' => $taxRate,
                    'tax_amount' => ($invoiceLine->amount * ($taxRate / 100)) / 100,
                ]);
            }

            $this->settingService->setInstallWide('db_update_0199', 1);
            $this->info('Updating Laravel CRM tax amounts complete');
        }

        if ($this->settingService->getInstallWide('db_update_1200') == 0) {
            $this->info('Updating Laravel CRM pipeline tables');

            $this->callSilent('db:seed', [
                '--class' => 'VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPipelineTablesSeeder',
                '--force' => true,
            ]);

            foreach (Lead::whereNull('number')->get() as $lead) {
                $this->info('Updating Laravel CRM lead #'.$lead->id);

                $this->backfillNumber($lead, 'lead');
            }

            foreach (Deal::whereNull('number')->get() as $deal) {
                $this->info('Updating Laravel CRM deal #'.$deal->id);

                $this->backfillNumber($deal, 'deal');
            }

            $this->settingService->setInstallWide('db_update_1200', 1);
            $this->info('Updating Laravel CRM pipeline tables complete.');
        }

        // SaaS catalogs are shared read-only defaults. Internal employee
        // teams must never provision tenants, roles or duplicate pipelines.
        $this->settingService->setInstallWide('db_update_1201', 1);

        // Only stamped on the success path, and install-wide for the same
        // reason as the db_update_* markers above. SystemCheckService reads it
        // back to answer "is the code ahead of the database?", so stamping it
        // after a partial run would silence the very alert that would have told
        // the operator to re-run this command.
        $this->settingService->setInstallWide(
            self::DB_VERSION_SETTING,
            config('laravel-crm.version')
        );

        $this->info('Laravel CRM is now updated.');

        return self::SUCCESS;
    }

    protected function backfillNumber(Model $model, string $type): void
    {
        if (config('laravel-crm.teams') && ! $model->team_id) {
            throw new \LogicException('Adopt legacy records into their business before backfilling document numbers.');
        }
        app(BusinessContext::class)->run($model->team_id, function () use ($model, $type) {
            $number = NumberGeneratorService::next($model::class);
            $prefix = $this->settingService->get($type.'_prefix');
            $model->update([$type.'_id' => $prefix.$number, 'prefix' => $prefix, 'number' => $number]);
        });
    }

    /**
     * Re-run the lookup-data seeders that upgrading hosts previously had to
     * know about and run by hand.
     *
     * All six are idempotent — updateOrInsert / firstOrCreate / existence-
     * checked inserts throughout — so re-running revokes nothing and duplicates
     * nothing. None is fatal: they seed reference data, not schema, and a host
     * that has customised its own lookup rows should not have its deploy fail
     * over one of them.
     *
     * Roles and lookup defaults remain installation-wide. Business-specific
     * customizations are never overwritten by these commands.
     */
    protected function seedLookupData(): void
    {
        $commands = ['laravelcrm:lead-sources'];

        if (config('laravel-crm.teams')) {
            $commands = array_merge($commands, [
                'laravelcrm:permissions',
                'laravelcrm:labels',
                'laravelcrm:addresstypes',
                'laravelcrm:contacttypes',
                'laravelcrm:organizationtypes',
            ]);
        }

        $this->info('Seeding lookup data...');

        foreach ($commands as $command) {
            try {
                $this->callSilent($command);
            } catch (\Throwable $e) {
                $this->warn("Could not run {$command}: ".$e->getMessage());
                $this->warn("Run \"php artisan {$command}\" manually to finish seeding it.");
            }
        }
    }

    /**
     * Whether to go ahead.
     *
     * Only ever asks on a production console with a real operator in front of
     * it. Defaults to yes so a deploy script that inherited the old
     * no-confirmation behaviour and does not pass --force still proceeds.
     */
    protected function confirmToProceed(): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        if (! app()->environment('production')) {
            return true;
        }

        return $this->confirm('Application in production. Run Laravel CRM database updates?', true);
    }
}
