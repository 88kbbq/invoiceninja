<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use App\Models\Gateway;
use App\Models\GatewayType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Gateway::find(66)) {
            $gateway = new Gateway;
            $gateway->id = 66;
            $gateway->name = 'TapPay';
            $gateway->key = Str::lower(Str::random(32));
            $gateway->provider = 'TapPay';
            $gateway->is_offsite = false;

            // Configuration fields
            $fields = new \stdClass;
            $fields->appId = '';
            $fields->appKey = '';
            $fields->partnerKey = '';
            $fields->merchantId = '';
            $fields->testMode = true;

            $gateway->fields = \json_encode($fields);
            $gateway->visible = true;
            $gateway->site_url = 'https://www.tappaysdk.com';
            $gateway->default_gateway_type_id = GatewayType::CREDIT_CARD;
            $gateway->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
