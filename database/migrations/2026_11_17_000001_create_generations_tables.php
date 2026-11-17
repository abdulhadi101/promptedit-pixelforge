<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money columns are integer nanodollars (see App\Support\Money).
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();                       // also the Pixelforge Idempotency-Key and metadata.generation_id
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('request_id');                          // client-generated, stops double-submits
            $t->unique(['user_id', 'request_id']);
            $t->string('pixelforge_id')->nullable()->unique();
            $t->text('prompt');
            $t->string('size');
            $t->string('quality');
            $t->json('reference_images');
            $t->string('status')->default('queued')->index();
            $t->unsignedBigInteger('estimated_charge_nano'); // expected price shown before submit
            $t->unsignedBigInteger('max_charge_nano');
            $t->json('usage')->nullable();
            $t->json('rates')->nullable();                   // rates snapshot from Pixelforge
            $t->unsignedBigInteger('provider_cost_nano')->nullable(); // what Pixelforge charged us
            $t->unsignedBigInteger('charge_nano')->nullable();        // what the user was charged
            $t->text('image_url')->nullable();
            $t->timestamp('image_url_expires_at')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable()->index(); // UTC; Pixelforge bills on this day
            $t->timestamps();
        });

        // Unique event id = webhook deduplication.
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->string('id')->primary();                     // evt_...
            $t->string('type');
            $t->uuid('generation_id')->nullable();
            $t->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
    }
};
