<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use VentureDrake\LaravelCrm\Models\Customer;

class AddCustomerToLaravelCrmDealsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $tableName = config('laravel-crm.db_table_prefix').'deals';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'customer_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->foreignUuidFor(Customer::class)->after('organization_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(config('laravel-crm.db_table_prefix').'deals', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
}
