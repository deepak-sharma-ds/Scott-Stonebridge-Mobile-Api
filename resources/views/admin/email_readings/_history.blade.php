{{-- Customer History: this reading's digest, the history it was given, and the history the customer's next order would get. --}}
@php
    $includedIds = (array) ($delivery->history_included_ids ?? []);
    $isSent = $delivery->status === \App\Models\EmailReadingDelivery::STATUS_SENT;
    $nextHistory = $historyPreview['history'];
@endphp

<div class="card p-4" id="customer-history">
    <h3 style="margin:0 0 1rem;font-size:1rem;">Customer History</h3>

    {{-- 1. This reading's History Digest --}}
    <h4 style="margin:0 0 0.5rem;font-size:0.875rem;">History digest of this reading</h4>
    @if($isSent)
        <p style="color:var(--text-muted);font-size:0.8125rem;margin:0 0 0.5rem;">
            @if(filled($delivery->history_digest))
                {{ $delivery->history_digest_is_manual ? 'Written by hand' : 'Written automatically' }}
                @if($delivery->history_digest_generated_at)
                    on {{ $delivery->history_digest_generated_at->format('d M Y H:i') }}
                @endif
                . It is shown to the model in this customer's later readings.
            @else
                No digest yet. Until one exists, this reading only contributes the customer's answers to their history.
            @endif
        </p>

        <form method="POST" action="{{ route('admin.email_readings.update_digest', $delivery) }}">
            @csrf
            @method('PUT')
            <textarea name="history_digest" rows="4" maxlength="2000" class="form-control"
                placeholder="A few sentences on what the customer was told">{{ old('history_digest', $delivery->history_digest) }}</textarea>
            @error('history_digest')
                <div style="color:var(--color-danger, #b00020);font-size:0.8125rem;">{{ $message }}</div>
            @enderror
            <div style="display:flex;gap:0.5rem;margin-top:0.5rem;align-items:center;">
                <button type="submit" class="btn btn-primary">Save digest</button>
                <span style="color:var(--text-muted);font-size:0.75rem;">Leave blank and save to remove it.</span>
            </div>
        </form>

        <form method="POST" action="{{ route('admin.email_readings.regenerate_digest', $delivery) }}" style="margin-top:0.75rem;">
            @csrf
            @if($delivery->history_digest_is_manual)
                <label style="display:block;font-size:0.8125rem;margin-bottom:0.5rem;">
                    <input type="checkbox" name="confirm_overwrite" value="1">
                    Replace the hand-written digest
                </label>
            @endif
            <button type="submit" class="btn btn-secondary">Regenerate digest</button>
        </form>
    @else
        <p style="color:var(--text-muted);margin:0;font-size:0.8125rem;">A digest is written after the email has been sent.</p>
    @endif

    {{-- 2. What this reading was given --}}
    <h4 style="margin:1.25rem 0 0.5rem;font-size:0.875rem;">History used for this reading</h4>
    @if($includedIds !== [] || $delivery->history_dropped_count)
        <p style="margin:0;font-size:0.8125rem;color:var(--text-secondary);">
            @if($includedIds !== [])
                Earlier readings included:
                @foreach($includedIds as $includedId)
                    <a href="{{ route('admin.email_readings.show', $includedId) }}">#{{ $includedId }}</a>{{ ! $loop->last ? ',' : '' }}
                @endforeach
                <br>
            @endif
            Not listed because of a limit: {{ (int) $delivery->history_dropped_count }}
            &middot; Approximate tokens: {{ (int) $delivery->history_tokens }}
        </p>
    @else
        <p style="color:var(--text-muted);margin:0;font-size:0.8125rem;">No earlier orders were used for this reading.</p>
    @endif

    {{-- 3. What the customer's next order would be given --}}
    <h4 style="margin:1.25rem 0 0.5rem;font-size:0.875rem;">History this customer's next order would receive</h4>
    @if(! $historyPreview['enabled'])
        <p style="color:var(--text-muted);margin:0;font-size:0.8125rem;">Customer history is switched off.</p>
    @else
        <p style="margin:0 0 0.5rem;font-size:0.8125rem;color:var(--text-secondary);">
            Listed: {{ count($nextHistory->includedIds) }}
            &middot; Not listed because of a limit: {{ $nextHistory->droppedCount }}
            &middot; Kept out (failed, cancelled, on hold): {{ $historyPreview['excluded'] }}
            &middot; Approximate tokens: {{ $nextHistory->tokens }}
        </p>
        @if($nextHistory->hasEntries())
            <pre style="white-space:pre-wrap;margin:0;max-height:24rem;overflow:auto;font-size:0.8125rem;">{{ $nextHistory->block }}</pre>
        @else
            <p style="color:var(--text-muted);margin:0;font-size:0.8125rem;">Nothing would be passed to the model.</p>
        @endif
    @endif
</div>
