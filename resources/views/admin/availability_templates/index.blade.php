@extends('admin.layouts.app')

@section('content')
    <style>
        /* STAGGERED CARD ENTRY */
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

        /* DAY CARD HOVER EFFECT */
        .day-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
            transition: 0.25s ease;
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

        /* DAY HEADER STYLE */
        .day-header {
            background: linear-gradient(135deg, #eef1ff, #fafbff);
            border-radius: 16px 16px 0 0;
            padding: 14px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* COLLAPSE ICON ROTATION */
        .day-toggle.collapsed i {
            transform: rotate(0deg);
        }

        .day-toggle i {
            transition: 0.3s;
            transform: rotate(180deg);
        }
    </style>

    <div class="container">

        <!-- Title -->
        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Availability Templates</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item active">Availability Templates</li>
                </ol>
            </div>
        </div>

        <!-- Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4></h4>
            <div>
                <a href="{{ route('admin.availability_templates.create') }}" class="btn btn-primary">Edit Templates</a>
                <a href="{{ route('admin.availability_templates.generate.form') }}" class="btn btn-success">Generate Slots</a>
            </div>
        </div>

        <!-- MAIN PREMIUM CARD -->
        <div class="card p-4 shadow-lg border-0"
            style="border-radius:22px; background: linear-gradient(145deg,#ffffff,#eef2ff);">

            <div class="text-center mb-4">
                <h3 class="fw-bold text-primary mb-1">Weekly Availability Overview</h3>
                <p class="text-muted">Your configured recurring time slots for each weekday</p>
            </div>

            @php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            @endphp

            <div class="row g-4">

                @foreach ($days as $index => $day)
                    @php $daySlots = $templates[$day] ?? []; @endphp

                    <div class="col-md-6" style="--i: {{ $index }}">
                        <div class="day-card shadow-sm border-0 h-100"
                            style="border-radius:18px; background:rgba(255,255,255,0.7); backdrop-filter:blur(9px);">

                            <!-- HEADER -->
                            <div class="day-header">
                                <h5 class="m-0 fw-bold text-primary">{{ $day }}</h5>

                                <button class="btn btn-light btn-sm day-toggle" data-bs-toggle="collapse"
                                    data-bs-target="#collapse-{{ $day }}">
                                    <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            </div>

                            <!-- BODY -->
                            <div id="collapse-{{ $day }}" class="collapse show">
                                <div class="card-body">

                                    @if (count($daySlots))
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach ($daySlots as $slot)
                                                <div class="slot-pill">
                                                    <i class="fa-regular fa-clock"></i>
                                                    {{ substr($slot->start_time, 0, 5) }} — {{ substr($slot->end_time, 0, 5) }}
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-muted fst-italic">No slots configured.</div>
                                    @endif

                                </div>
                            </div>

                        </div>
                    </div>
                @endforeach

            </div>

        </div>

    </div>
@endsection
