@extends('admin.layouts.app')

@section('content')
    <div class="container">

        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Availability Templates</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.availability_templates.index') }}">Availability
                            Templates List</a></li>
                    <li class="breadcrumb-item active">Edit Weekly Templates</li>

                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <form method="POST" action="{{ route('admin.availability_templates.store') }}">
                    @csrf

                    @php
                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        $templates = App\Models\AvailabilityTemplate::where('user_id', auth()->id())->get();
                    @endphp

                    {{-- <div class="card p-3">
                        @foreach ($days as $day)
                            <div class="mb-3">
                                <h5>{{ $day }}</h5>
                                <div id="slots-{{ $day }}">
                                    <!-- Initially empty or populate via old() if present -->
                                    @if (old('templates.' . $day))
                                        @foreach (old('templates.' . $day) as $i => $slot)
                                            <div class="d-flex gap-2 mb-2 slot-row">
                                                <input type="time"
                                                    name="templates[{{ $day }}][{{ $i }}][start]"
                                                    value="{{ $slot['start'] }}" class="form-control w-auto" />
                                                <input type="time"
                                                    name="templates[{{ $day }}][{{ $i }}][end]"
                                                    value="{{ $slot['end'] }}" class="form-control w-auto" />
                                                <button type="button" class="btn btn-danger remove-slot">X</button>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary add-slot"
                                    data-day="{{ $day }}">Add Slot</button>
                            </div>
                        @endforeach

                        <div class="mt-3">
                            <button class="btn btn-primary">Save Templates</button>
                        </div>
                    </div> --}}
                    <div class="card p-3">
                        @foreach ($days as $day)
                            <div class="mb-3">
                                <h5>{{ $day }}</h5>

                                @php
                                    $daySlots = $templates->where('day_of_week', $day);
                                @endphp

                                <div id="slots-{{ $day }}">
                                    @foreach ($daySlots as $i => $slot)
                                        <div class="d-flex gap-2 mb-2 slot-row">
                                            <input type="time"
                                                name="templates[{{ $day }}][{{ $i }}][start]"
                                                value="{{ $slot->start_time }}" class="form-control w-auto" />

                                            <input type="time"
                                                name="templates[{{ $day }}][{{ $i }}][end]"
                                                value="{{ $slot->end_time }}" class="form-control w-auto" />

                                            <button type="button" class="btn btn-danger remove-slot">X</button>
                                        </div>
                                    @endforeach
                                </div>

                                <button type="button" class="btn btn-sm btn-outline-primary add-slot"
                                    data-day="{{ $day }}">Add Slot</button>
                            </div>
                        @endforeach

                        <div class="mt-3">
                            <button class="btn btn-primary">Save Templates</button>
                        </div>
                    </div>

                </form>
            </div>
        </div>

    </div>
@endsection

@section('custom_js_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.add-slot').forEach(btn => {
                btn.addEventListener('click', () => {
                    const day = btn.dataset.day;
                    const container = document.getElementById('slots-' + day);
                    const index = container.querySelectorAll('.slot-row').length;
                    const html = `
                <div class="d-flex gap-2 mb-2 slot-row">
                    <input type="time" name="templates[${day}][${index}][start]" class="form-control w-auto" required />
                    <input type="time" name="templates[${day}][${index}][end]" class="form-control w-auto" required />
                    <button type="button" class="btn btn-danger remove-slot">X</button>
                </div>
            `;
                    container.insertAdjacentHTML('beforeend', html);
                });
            });

            document.body.addEventListener('click', function(e) {
                if (e.target.matches('.remove-slot')) {
                    e.target.closest('.slot-row').remove();
                }
            });
        });
    </script>
@endsection
