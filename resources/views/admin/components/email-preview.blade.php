@php
    $editable = $editable ?? false;
    $isReading = $isReading ?? false;
    $title = $title ?? 'Customer Email Preview';
    $sendTestUrl = $sendTestUrl ?? null;
    $context = $context ?? ($isReading ? 'reading_delivery' : 'campaign_delivery');
    $productId = $productId ?? null;
@endphp

<div x-data="emailPreviewBox({
    previewUrl: @js($previewUrl),
    sendTestUrl: @js($sendTestUrl),
    editable: @js((bool) $editable),
    isReading: @js((bool) $isReading),
    context: @js($context),
    productId: @js($productId),
})" class="card p-4 email-preview-card">
    <div class="email-preview-toolbar" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <h3 style="margin:0;font-size:1rem;">{{ $title }}</h3>
            <span style="font-size:0.75rem;color:var(--text-muted);">(Real Mailable render)</span>
        </div>

        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            @if($sendTestUrl)
                <button type="button" class="btn btn-sm btn-outline-success" @click="sendTestToMe()" :disabled="sendingTest || loading">
                    <span x-show="!sendingTest">✉ Send Test to Me</span>
                    <span x-show="sendingTest" x-cloak>Sending…</span>
                </button>
            @endif

            @if($editable)
                <button type="button" class="btn btn-sm btn-secondary" @click="refreshPreview()" :disabled="loading || sendingTest">
                    <span x-show="!loading">↻ Refresh Preview</span>
                    <span x-show="loading" x-cloak>Refreshing…</span>
                </button>
            @endif

            <div class="btn-group btn-group-sm" role="group" aria-label="Device view toggle">
                <button type="button" class="btn btn-sm"
                    :class="mode === 'desktop' ? 'btn-primary' : 'btn-outline-primary'"
                    @click="mode = 'desktop'">
                    Desktop (640px)
                </button>
                <button type="button" class="btn btn-sm"
                    :class="mode === 'mobile' ? 'btn-primary' : 'btn-outline-primary'"
                    @click="mode = 'mobile'">
                    Mobile (375px)
                </button>
            </div>
        </div>
    </div>

    <div class="email-preview-stage" style="display:flex;justify-content:center;background:#f8fafc;padding:1.25rem;border-radius:8px;border:1px solid var(--card-border);overflow:auto;">
        <div :style="mode === 'desktop' ? 'width:100%;max-width:680px;display:flex;justify-content:center;' : 'width:375px;max-width:375px;display:flex;justify-content:center;box-shadow:0 0 0 8px #1e293b, var(--shadow-lg);border-radius:20px;overflow:hidden;'">
            <iframe
                x-ref="iframe"
                src="{{ $previewUrl }}"
                sandbox="allow-same-origin"
                :style="mode === 'desktop'
                    ? 'width:100%;height:720px;border:1px solid var(--card-border);border-radius:8px;background:#ffffff;box-shadow:var(--shadow-sm);'
                    : 'width:375px;height:667px;border:none;background:#ffffff;'"
                title="Email Preview">
            </iframe>
        </div>
    </div>
</div>

<script>
    if (typeof window.emailPreviewBox === 'undefined') {
        window.emailPreviewBox = function(config) {
            return {
                previewUrl: config.previewUrl,
                sendTestUrl: config.sendTestUrl || null,
                editable: Boolean(config.editable),
                isReading: Boolean(config.isReading),
                context: config.context || 'delivery',
                productId: config.productId || null,
                mode: 'desktop',
                loading: false,
                sendingTest: false,
                customBody: null,

                init() {
                    if (this.editable) {
                        window.addEventListener('email-preview-refresh', (e) => {
                            this.handleRefreshEvent(e);
                        });
                        window.addEventListener('reading-preview-refresh', (e) => {
                            this.handleRefreshEvent(e);
                        });
                    }
                },

                handleRefreshEvent(e) {
                    if (e && e.detail) {
                        if (this.productId && e.detail.productId && this.productId != e.detail.productId) {
                            return;
                        }
                        if (typeof e.detail.body !== 'undefined') {
                            this.customBody = e.detail.body;
                        }
                    }
                    this.refreshPreview();
                },

                getPayload() {
                    const payload = {};

                    // Reading product page context
                    if (this.context === 'reading_product') {
                        const nameEl = document.getElementById('name');
                        if (nameEl) payload.name = nameEl.value;

                        const subjEl = document.getElementById('email_subject');
                        if (subjEl) payload.email_subject = subjEl.value;

                        const testNameEl = document.querySelector('[x-model="testName"]');
                        if (testNameEl && testNameEl.value) {
                            payload.customer_name = testNameEl.value;
                        }

                        const contentEl = document.getElementById('email_content');
                        if (contentEl) {
                            payload.email_content = contentEl.ckEditorInstance ? contentEl.ckEditorInstance.getData() : contentEl.value;
                        }

                        const footerEl = document.getElementById('email_footer');
                        if (footerEl) {
                            payload.email_footer = footerEl.ckEditorInstance ? footerEl.ckEditorInstance.getData() : footerEl.value;
                        }

                        if (this.customBody !== null) {
                            payload.body = this.customBody;
                        }
                        return payload;
                    }

                    // Campaign product page context
                    if (this.context === 'campaign_product') {
                        const bodyId = this.productId ? ('body_' + this.productId) : 'body';
                        const bodyEl = document.getElementById(bodyId);
                        let editorValue = '';
                        if (bodyEl) {
                            editorValue = bodyEl.ckEditorInstance ? bodyEl.ckEditorInstance.getData() : bodyEl.value;
                        }

                        const formEl = bodyEl ? bodyEl.closest('form') : document;
                        if (formEl) {
                            const nameEl = formEl.querySelector('[name="customer_name"]') || document.getElementById('customer_name');
                            if (nameEl && nameEl.value) {
                                payload.customer_name = nameEl.value;
                            }
                            const contentEl = formEl.querySelector('[name="email_content"]');
                            if (contentEl) {
                                payload.email_content = contentEl.ckEditorInstance ? contentEl.ckEditorInstance.getData() : contentEl.value;
                            }
                            const footerEl = formEl.querySelector('[name="email_footer"]');
                            if (footerEl) {
                                payload.email_footer = footerEl.ckEditorInstance ? footerEl.ckEditorInstance.getData() : footerEl.value;
                            }
                        }

                        if (this.customBody !== null) {
                            payload.body = this.customBody;
                            this.customBody = null;
                        } else if (editorValue) {
                            payload.body = editorValue;
                        }
                        return payload;
                    }

                    // Delivery pages context
                    const nameEl = document.getElementById('customer_name');
                    if (nameEl) payload.customer_name = nameEl.value;

                    const emailEl = document.getElementById('customer_email');
                    if (emailEl) payload.customer_email = emailEl.value;

                    if (this.isReading) {
                        const aiEl = document.getElementById('ai_response');
                        if (aiEl) {
                            payload.ai_response = aiEl.ckEditorInstance ? aiEl.ckEditorInstance.getData() : aiEl.value;
                        }
                    } else {
                        const prodEl = document.getElementById('campaign_product_id');
                        if (prodEl) payload.campaign_product_id = prodEl.value;
                    }

                    return payload;
                },

                async refreshPreview() {
                    if (!this.editable) return;
                    this.loading = true;

                    try {
                        const payload = this.getPayload();
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch(this.previewUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'text/html',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });

                        if (!res.ok) {
                            throw new Error('Preview failed to load.');
                        }

                        const html = await res.text();
                        if (this.$refs.iframe) {
                            this.$refs.iframe.srcdoc = html;
                        }
                    } catch (err) {
                        if (window.adminNotify) {
                            window.adminNotify(err.message || 'Failed to refresh preview.', 'error');
                        }
                    } finally {
                        this.loading = false;
                    }
                },

                async sendTestToMe() {
                    if (!this.sendTestUrl || this.sendingTest) return;
                    this.sendingTest = true;

                    try {
                        const payload = this.getPayload();
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const res = await fetch(this.sendTestUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });

                        const data = await res.json();
                        if (!res.ok || !data.success) {
                            throw new Error(data.message || 'Failed to send test email.');
                        }

                        if (window.adminNotify) {
                            window.adminNotify(data.message || 'Test email sent successfully.', 'success');
                        }
                    } catch (err) {
                        if (window.adminNotify) {
                            window.adminNotify(err.message || 'Failed to send test email.', 'error');
                        }
                    } finally {
                        this.sendingTest = false;
                    }
                }
            };
        };
    }
</script>
