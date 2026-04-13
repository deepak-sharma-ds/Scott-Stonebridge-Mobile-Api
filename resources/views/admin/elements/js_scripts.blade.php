{{-- ─── Admin JS Scripts ──────────────────────────────────────────
     Load order:
       1. jQuery          — required by DateRangePicker + legacy pages
       2. Moment.js       — required by DateRangePicker
       3. Bootstrap JS    — required by Modal (booking, entitlements pages)
       4. DateRangePicker
       5. SweetAlert2     — legacy confirm dialogs
       6. HLS.js          — audio streaming (audio pages)
       7. Vite admin JS   — Alpine.js + all admin modules (must be LAST)

     NOTE: Bootstrap CSS is NOT loaded — only the JS bundle.
           Alpine.js and Bootstrap JS coexist without conflict.
─────────────────────────────────────────────────────────────── --}}

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>

{{-- Vite-compiled admin JS (Alpine.js + notifications + loader + confirm) --}}
@vite(['resources/js/admin/app.js'])
