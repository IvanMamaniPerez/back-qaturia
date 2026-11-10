<?php

namespace VentureDrake\LaravelCrm\Database\Seeders;

use Illuminate\Database\Seeder;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\LegacyUuid;

class LaravelCrmPipelineTablesSeeder extends Seeder
{
    private function legacyUuid(int $id): string
    {
        return LegacyUuid::fromInteger($id);
    }

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Pipelines stage probabilities
        if (! Setting::where('name', 'db_seeded_pipeline_probabilities')->first()) {
            $items = [
                [
                    [
                        'id' => $this->legacyUuid(1),
                    ],
                    [
                        'name' => 'New',
                        'percent' => 1,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(2),
                    ],
                    [
                        'name' => '10%',
                        'percent' => 10,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(3),
                    ],
                    [
                        'name' => '20%',
                        'percent' => 20,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(4),
                    ],
                    [
                        'name' => '30%',
                        'percent' => 30,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(5),
                    ],
                    [
                        'name' => '40%',
                        'percent' => 40,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(6),
                    ],
                    [
                        'name' => '50%',
                        'percent' => 50,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(7),
                    ],
                    [
                        'name' => '60%',
                        'percent' => 60,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(8),
                    ],
                    [
                        'name' => '70%',
                        'percent' => 70,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(9),
                    ],
                    [
                        'name' => '80%',
                        'percent' => 80,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(10),
                    ],
                    [
                        'name' => '90%',
                        'percent' => 90,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(11),
                    ],
                    [
                        'name' => 'Won',
                        'percent' => 100,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(12),
                    ],
                    [
                        'name' => 'Lost',
                        'percent' => 0,
                    ],
                ],
            ];

            foreach ($items as $item) {
                PipelineStageProbability::firstOrCreate($item[0], $item[1]);
            }

            Setting::updateOrCreate([
                'global' => 1,
                'name' => 'db_seeded_pipeline_probabilities',
            ], [
                'value' => 1,
            ]);
        }

        // Pipelines
        if (! Setting::where('name', 'db_seeded_pipelines')->first()) {
            $items = [
                [
                    [
                        'id' => $this->legacyUuid(1),
                    ],
                    [
                        'name' => 'Lead Pipeline',
                        'model' => get_class(new Lead),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(2),
                    ],
                    [
                        'name' => 'Deal Pipeline',
                        'model' => get_class(new Deal),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(3),
                    ],
                    [
                        'name' => 'Quote Pipeline',
                        'model' => get_class(new Quote),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(4),
                    ],
                    [
                        'name' => 'Order Pipeline',
                        'model' => get_class(new Order),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(5),
                    ],
                    [
                        'name' => 'Invoice Pipeline',
                        'model' => get_class(new Invoice),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(6),
                    ],
                    [
                        'name' => 'Delivery Pipeline',
                        'model' => get_class(new Delivery),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(7),
                    ],
                    [
                        'name' => 'Purchase Order Pipeline',
                        'model' => get_class(new PurchaseOrder),
                    ],
                ],
            ];

            foreach ($items as $item) {
                Pipeline::firstOrCreate($item[0], $item[1]);
            }

            Setting::updateOrCreate([
                'global' => 1,
                'name' => 'db_seeded_pipelines',
            ], [
                'value' => 1,
            ]);
        }

        // Pipelines stages
        if (! Setting::where('name', 'db_seeded_pipelines_stages')->first()) {
            $items = [
                // Leads
                [
                    [
                        'id' => $this->legacyUuid(1),
                    ],
                    [
                        'name' => 'New',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(2),
                    ],
                    [
                        'name' => 'Appointment Scheduled',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(3),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(3),
                    ],
                    [
                        'name' => 'Qualified To Buy',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(5),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(4),
                    ],
                    [
                        'name' => 'Presentation Scheduled',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(7),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(5),
                    ],
                    [
                        'name' => 'Decision Maker Bought-In',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(6),
                    ],
                    [
                        'name' => 'Contract Sent',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(10),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(7),
                    ],
                    [
                        'name' => 'Closed Won',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(8),
                    ],
                    [
                        'name' => 'Closed Lost',
                        'pipeline_id' => $this->legacyUuid(1),
                        'pipeline_stage_probability_id' => $this->legacyUuid(12),
                    ],
                ],
                // Deals
                [
                    [
                        'id' => $this->legacyUuid(9),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                        'order' => 1,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(35),
                    ],
                    [
                        'name' => 'Qualified',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(3),
                        'order' => 2,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(36),
                    ],
                    [
                        'name' => 'Proposal Sent',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(5),
                        'order' => 3,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(37),
                    ],
                    [
                        'name' => 'Negotiation',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(7),
                        'order' => 4,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(10),
                    ],
                    [
                        'name' => 'Pending',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                        'order' => 5,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(11),
                    ],
                    [
                        'name' => 'Closed Won',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                        'order' => 6,
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(12),
                    ],
                    [
                        'name' => 'Closed Lost',
                        'pipeline_id' => $this->legacyUuid(2),
                        'pipeline_stage_probability_id' => $this->legacyUuid(12),
                        'order' => 7,
                    ],
                ],
                // Quotes
                [
                    [
                        'id' => $this->legacyUuid(13),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(3),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(14),
                    ],
                    [
                        'name' => 'Sent',
                        'pipeline_id' => $this->legacyUuid(3),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(15),
                    ],
                    [
                        'name' => 'Accepted',
                        'pipeline_id' => $this->legacyUuid(3),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(16),
                    ],
                    [
                        'name' => 'Rejected',
                        'pipeline_id' => $this->legacyUuid(3),
                        'pipeline_stage_probability_id' => $this->legacyUuid(12),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(17),
                    ],
                    [
                        'name' => 'Ordered',
                        'pipeline_id' => $this->legacyUuid(3),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                // Orders
                [
                    [
                        'id' => $this->legacyUuid(18),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(4),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(19),
                    ],
                    [
                        'name' => 'Open',
                        'pipeline_id' => $this->legacyUuid(4),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(20),
                    ],
                    [
                        'name' => 'Invoiced',
                        'pipeline_id' => $this->legacyUuid(4),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(21),
                    ],
                    [
                        'name' => 'Delivered',
                        'pipeline_id' => $this->legacyUuid(4),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(22),
                    ],
                    [
                        'name' => 'Completed',
                        'pipeline_id' => $this->legacyUuid(4),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                // Invoices
                [
                    [
                        'id' => $this->legacyUuid(23),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(5),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(24),
                    ],
                    [
                        'name' => 'Awaiting Approval',
                        'pipeline_id' => $this->legacyUuid(5),
                        'pipeline_stage_probability_id' => $this->legacyUuid(5),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(25),
                    ],
                    [
                        'name' => 'Awaiting Payment',
                        'pipeline_id' => $this->legacyUuid(5),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(26),
                    ],
                    [
                        'name' => 'Paid',
                        'pipeline_id' => $this->legacyUuid(5),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                // Deliveries
                [
                    [
                        'id' => $this->legacyUuid(27),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(6),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(28),
                    ],
                    [
                        'name' => 'Packed',
                        'pipeline_id' => $this->legacyUuid(6),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(29),
                    ],
                    [
                        'name' => 'Sent',
                        'pipeline_id' => $this->legacyUuid(6),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(30),
                    ],
                    [
                        'name' => 'Delivered',
                        'pipeline_id' => $this->legacyUuid(6),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
                // Purchase Orders
                [
                    [
                        'id' => $this->legacyUuid(31),
                    ],
                    [
                        'name' => 'Draft',
                        'pipeline_id' => $this->legacyUuid(7),
                        'pipeline_stage_probability_id' => $this->legacyUuid(1),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(32),
                    ],
                    [
                        'name' => 'Awaiting Approval',
                        'pipeline_id' => $this->legacyUuid(7),
                        'pipeline_stage_probability_id' => $this->legacyUuid(5),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(33),
                    ],
                    [
                        'name' => 'Approved',
                        'pipeline_id' => $this->legacyUuid(7),
                        'pipeline_stage_probability_id' => $this->legacyUuid(9),
                    ],
                ],
                [
                    [
                        'id' => $this->legacyUuid(34),
                    ],
                    [
                        'name' => 'Paid',
                        'pipeline_id' => $this->legacyUuid(7),
                        'pipeline_stage_probability_id' => $this->legacyUuid(11),
                    ],
                ],
            ];

            foreach ($items as $item) {
                PipelineStage::firstOrCreate($item[0], $item[1]);
            }

            Setting::updateOrCreate([
                'global' => 1,
                'name' => 'db_seeded_pipelines_stages',
            ], [
                'value' => 1,
            ]);
        }
    }
}
