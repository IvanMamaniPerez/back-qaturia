<?php

namespace VentureDrake\LaravelCrm\Database\Seeders;

use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\ContactType;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\LeadStatus;
use VentureDrake\LaravelCrm\Models\OrganizationType;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\Timezone;
use VentureDrake\LaravelCrm\Support\LegacyUuid;

class LaravelCrmTablesSeeder extends Seeder
{
    private function legacyUuid(int $id): string
    {
        return LegacyUuid::fromInteger($id);
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Labels
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'Hot',
                    'hex' => 'dc3545',
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Cold',
                    'hex' => '007bff',
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(3),
                ],
                [
                    'name' => 'Warm',
                    'hex' => 'ffc107',
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
        ];

        foreach ($items as $item) {
            Label::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_labels',
        ], [
            'value' => 1,
        ]);

        // Lead statuses (DEPRECATED)
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'Lead In',
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Contacted',
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
        ];

        foreach ($items as $item) {
            LeadStatus::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_lead_statuses',
        ], [
            'value' => 1,
        ]);

        // Organization Types
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'Sole Trader',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Partnership',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(3),
                ],
                [
                    'name' => 'Company',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(4),
                ],
                [
                    'name' => 'Trust',
                ],
            ],
        ];

        foreach ($items as $item) {
            OrganizationType::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_organization_types',
        ], [
            'value' => 1,
        ]);

        // Address types
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'Current',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Previous',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(3),
                ],
                [
                    'name' => 'Postal',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(4),
                ],
                [
                    'name' => 'Business',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(5),
                ],
                [
                    'name' => 'Billing',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(6),
                ],
                [
                    'name' => 'Shipping',
                ],
            ],
        ];

        foreach ($items as $item) {
            AddressType::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_address_types',
        ], [
            'value' => 1,
        ]);

        // Contact types
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'Primary',
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Secondary',
                ],
            ],
        ];

        foreach ($items as $item) {
            ContactType::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_contact_types',
        ], [
            'value' => 1,
        ]);

        // Feature statuses
        $items = [
            [
                [
                    'id' => $this->legacyUuid(1),
                ],
                [
                    'name' => 'New',
                    'color' => '#6c757d',
                    'order' => 1,
                    'is_default' => true,
                    'is_closed' => false,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(2),
                ],
                [
                    'name' => 'Considering',
                    'color' => '#ffc107',
                    'order' => 2,
                    'is_default' => false,
                    'is_closed' => false,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(3),
                ],
                [
                    'name' => 'Planned',
                    'color' => '#007bff',
                    'order' => 3,
                    'is_default' => false,
                    'is_closed' => false,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(4),
                ],
                [
                    'name' => 'In Progress',
                    'color' => '#17a2b8',
                    'order' => 4,
                    'is_default' => false,
                    'is_closed' => false,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(5),
                ],
                [
                    'name' => 'Completed',
                    'color' => '#28a745',
                    'order' => 5,
                    'is_default' => false,
                    'is_closed' => true,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
            [
                [
                    'id' => $this->legacyUuid(6),
                ],
                [
                    'name' => 'Declined',
                    'color' => '#dc3545',
                    'order' => 6,
                    'is_default' => false,
                    'is_closed' => true,
                    'external_id' => Uuid::uuid4()->toString(),
                ],
            ],
        ];

        foreach ($items as $item) {
            FeatureStatus::withTrashed()->firstOrCreate($item[0], $item[1]);
        }

        Setting::updateOrCreate([
            'global' => 1,
            'name' => 'db_seeded_feature_statuses',
        ], [
            'value' => 1,
        ]);

        // Pipelines
        $this->callSilent(LaravelCrmPipelineTablesSeeder::class);

        $now = new \DateTimeImmutable;
        foreach (timezone_identifiers_list() as $zone) {
            $offset = $now->setTimezone(new \DateTimeZone($zone))->format('P');

            Timezone::firstOrCreate(['name' => $zone], [
                'offset' => $offset,
                'diff_from_gtm' => 'UTC/GMT '.$offset,
            ]);
        }

        $this->callSilent(LaravelCrmPermissionsSeeder::class);
    }
}
