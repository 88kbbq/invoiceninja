<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Gateway;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $gateway = Gateway::find(66);

        if (! $gateway) {
            return;
        }

        $fields = json_decode($gateway->fields ?: '{}');

        $fields->testAppId = $fields->testAppId ?? '13355';
        $fields->testAppKey = $fields->testAppKey ?? 'app_2FReMHY00VYwtVjQ3It1gPSk8htdAyDrX0ijMv4AmQF8CfeJjina0dcNkFv0';
        $fields->testPartnerKey = $fields->testPartnerKey ?? 'babakevin';
        $fields->testMerchantId = $fields->testMerchantId ?? 'tappay.kyc.test';

        $gateway->fields = json_encode($fields);
        $gateway->save();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $gateway = Gateway::find(66);

        if (! $gateway) {
            return;
        }

        $fields = json_decode($gateway->fields ?: '{}');

        unset($fields->testAppId, $fields->testAppKey, $fields->testPartnerKey, $fields->testMerchantId);

        $gateway->fields = json_encode($fields);
        $gateway->save();
    }
};
