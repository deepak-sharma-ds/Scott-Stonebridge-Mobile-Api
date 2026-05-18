<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Captured customer emails from in-chat lead forms. Drives abandon
     * recovery emails (Step 4) and feeds the conversion funnel (Step 9).
     *
     * Linked to ai_conversations.session_id loosely (string column, not FK)
     * so a session that is later deleted does not cascade-drop the captured
     * lead — we still want to send the recovery email and analyse the funnel.
     */
    public function up(): void
    {
        Schema::create('ai_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id')->index();
            $table->string('shop_domain')->index();
            $table->string('email');
            $table->string('name')->nullable();
            $table->text('issue_summary')->nullable();
            $table->json('cart_snapshot_json')->nullable();
            $table->enum('source', ['proactive_trigger', 'manual_input', 'escalation']);
            $table->enum('status', ['new', 'recovery_sent', 'converted', 'unsubscribed'])->default('new');
            $table->timestamp('recovery_sent_at')->nullable();
            $table->timestamps();

            // One lead per (session, email) pair — re-submits short-circuit.
            $table->unique(['session_id', 'email'], 'ai_leads_session_email_unique');
            $table->index(['shop_domain', 'status'], 'ai_leads_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_leads');
    }
};
