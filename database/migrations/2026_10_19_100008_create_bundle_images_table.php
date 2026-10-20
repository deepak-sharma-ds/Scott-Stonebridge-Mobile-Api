<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple uploaded images per bundle (replaces the single image_url column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->string('path', 500);              // storage/app/public/...
            $table->string('original_name')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('shopify_media_id')->nullable();
            $table->string('shopify_url', 1000)->nullable();
            $table->timestamps();
        });

        Schema::table('bundles', function (Blueprint $table) {
            // Shopify media the app added automatically from component images
            $table->json('auto_media_ids')->nullable()->after('shopify_handle');
            // Shopify media of removed uploads, deleted on the next sync
            $table->json('pending_media_deletes')->nullable()->after('auto_media_ids');
        });

        // Carry over the single image from the previous implementation.
        if (Schema::hasColumn('bundles', 'image_url')) {
            foreach (DB::table('bundles')->whereNotNull('image_url')->get(['id', 'image_url']) as $row) {
                $path = ltrim((string) parse_url($row->image_url, PHP_URL_PATH), '/');
                $path = preg_replace('#^storage/#', '', $path);
                if ($path) {
                    DB::table('bundle_images')->insert([
                        'bundle_id'  => $row->id,
                        'path'       => $path,
                        'position'   => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            Schema::table('bundles', function (Blueprint $table) {
                $table->dropColumn(['image_url', 'shopify_image_url']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->string('image_url', 1000)->nullable()->after('description');
            $table->string('shopify_image_url', 1000)->nullable()->after('image_url');
            $table->dropColumn(['auto_media_ids', 'pending_media_deletes']);
        });

        Schema::dropIfExists('bundle_images');
    }
};
