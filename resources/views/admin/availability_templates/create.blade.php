@extends('admin.layouts.app')

@section('content')
    <style>
        /* Fade-up stagger animation */
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(25px) scale(0.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .day-card {
            animation: fadeUp 0.4s ease forwards;
            animation-delay: calc(var(--i) * 0.07s);
        }

        /* Hover glow */
        .day-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
            transition: 0.25s ease;
        }

        /* Editable slot row */
        .slot-edit-row {
            background: #fff;
            border: 1px solid #e5e7ef;
            padding: 12px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: 0.2s ease;
        }

        .slot-edit-row:hover {
            transform: translateX(4px);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.12);
        }

        /* Collapse header */
        .day-header {
            background: linear-gradient(135deg, #eef1ff, #fafbff);
            border-radius: 16px 16px 0 0;
            padding: 16px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Chevron rotation */
        .day-toggle.collapsed i {
            transform: rotate(0deg);
        }

        .day-toggle i {
            transform: rotate(180deg);
            transition: 0.25s ease;
        }

        /* Add button pulse */
        .add-slot:hover {
            transform: scale(1.06);
        }

        /* Delete animation */
        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: scale(1);
            }

            to {
                opacity: 0;
                transform: scale(0.9);
            }
        }

        .removed {
            animation: fadeOut .25s forwards;
        }

        /* SLOT PILL */
        .slot-pill {
            background: linear-gradient(135deg, #0B27C6, #F24FD8);
            color: white;
            padding: 8px 12px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15);
            transition: 0.2s ease;
        }

        .slot-pill:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.20);
        }
    </style>

    <div class="container">

        <!-- Page Header -->
        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <h4 class="welcome-text">Edit Availability Templates</h4>
            </div>
            <div class="col-sm-6 p-0 d-flex justify-content-sm-end">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.availability_templates.index') }}">Templates</a></li>
                    <li class="breadcrumb-item active">Edit Weekly Templates</li>
                </ol>
            </div>
        </div>

        <!-- FORM START -->
        <form method="POST" action="{{ route('admin.availability_templates.store') }}">
            @csrf

            @php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                $templates = App\Models\AvailabilityTemplate::where('user_id', auth()->id())->get();
            @endphp

            <div class="card p-4 shadow-lg border-0"
                style="border-radius:22px; background: linear-gradient(145deg,#ffffff,#eef2ff);">

                <div class="text-center mb-4">
                    <h3 class="fw-bold text-primary mb-1">Customize Weekly Availability</h3>
                    <p class="text-muted">Add or modify your recurring availability schedule</p>
                </div>

                <div class="row g-4">
                    @foreach ($days as $i => $day)
                        @php
                            $daySlots = $templates->where('day_of_week', $day);
                        @endphp

                        <div class="col-md-6" style="--i:{{ $i }}">
                            <div class="day-card shadow-sm border-0 h-100"
                                style="border-radius:18px; background:rgba(255,255,255,0.7); backdrop-filter:blur(9px);">

                                <!-- Header -->
                                <div class="day-header">
                                    <h5 class="fw-bold text-primary m-0">{{ $day }}</h5>

                                    <button type="button" class="btn btn-light btn-sm day-toggle" data-bs-toggle="collapse"
                                        data-bs-target="#collapse-{{ $day }}">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>

                                <!-- Body -->
                                <div id="collapse-{{ $day }}" class="collapse show">
                                    <div class="card-body">

                                        <div id="slots-{{ $day }}">
                                            @foreach ($daySlots as $k => $slot)
                                                <div class="slot-edit-row mb-2 slot-row">
                                                    <input type="time"
                                                        name="templates[{{ $day }}][{{ $k }}][start]"
                                                        value="{{ $slot->start_time }}" class="form-control w-auto slot-pill">

                                                    <input type="time"
                                                        name="templates[{{ $day }}][{{ $k }}][end]"
                                                        value="{{ $slot->end_time }}" class="form-control w-auto slot-pill">

                                                    <button type="button" class="btn btn-danger btn-sm remove-slot">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>

                                        <button type="button" class="btn btn-outline-primary btn-sm mt-2 add-slot"
                                            data-day="{{ $day }}">
                                            <i class="fa-solid fa-plus"></i> Add Slot
                                        </button>

                                    </div>
                                </div>

                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="text-end mt-4">
                    <button class="btn btn-primary px-5 py-2 fw-bold">
                        Save Templates
                    </button>
                </div>

            </div>
        </form>
    </div>
@endsection

@section('custom_js_scripts')
    <script>
        document.addEventListener("DOMContentLoaded", function() {

            document.querySelectorAll(".add-slot").forEach(btn => {
                btn.addEventListener("click", () => {
                    const day = btn.dataset.day;
                    const container = document.getElementById("slots-" + day);
                    const index = container.querySelectorAll(".slot-row").length;

                    container.insertAdjacentHTML("beforeend", `
                <div class="slot-edit-row mb-2 slot-row newly-added">
                    <input type="time"
                           name="templates[${day}][${index}][start]"
                           class="form-control w-auto slot-pill"
                           required>

                    <input type="time"
                           name="templates[${day}][${index}][end]"
                           class="form-control w-auto slot-pill"
                           required>

                    <button type="button" class="btn btn-danger btn-sm remove-slot">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            `);
                });
            });

            document.addEventListener("click", function(e) {
                if (e.target.closest(".remove-slot")) {
                    const row = e.target.closest(".slot-row");
                    row.classList.add("removed");
                    setTimeout(() => row.remove(), 250);
                }
            });
        });
    </script>
@endsection
