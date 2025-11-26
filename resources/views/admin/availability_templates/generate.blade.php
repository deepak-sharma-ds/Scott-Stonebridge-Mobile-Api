@extends('admin.layouts.app')

@section('content')
    <style>
        /* Fade animation */
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

        .animate-card {
            animation: fadeUp 0.45s ease forwards;
        }

        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.12);
            transition: .25s ease;
        }

        /* Main container card */
        .generate-card {
            border-radius: 22px;
            background: linear-gradient(145deg, #ffffff, #eef2ff);
            padding: 35px !important;
            /* max-width: 850px; */
            /* margin: 0 auto; */
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.06);
        }

        /* Section title box */
        .section-header {
            background: linear-gradient(135deg, #eef1ff, #fafbff);
            padding: 14px 20px;
            border-radius: 14px;
            font-weight: 600;
            color: #3a4cd7;
            margin-bottom: 12px;
        }

        /* Input styles */
        .form-select,
        .form-control {
            border-radius: 12px !important;
            padding: 10px 14px !important;
            border: 1px solid #d6d9e3 !important;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.05) !important;
            font-size: 15px !important;
            width: 100% !important;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: #6370ff;
            box-shadow: 0 0 0 3px rgba(103, 122, 255, 0.2);
        }

        .input-label {
            font-weight: 600;
            margin-bottom: 6px;
            color: #444;
        }
    </style>

    <div class="container">

        <!-- HEADER -->
        <div class="row page-titles mx-0 mb-3">
            <div class="col-sm-6 p-0">
                <div class="welcome-text">
                    <h4>Generate Availability Slots</h4>
                </div>
            </div>
            <div class="col-sm-6 p-0 d-flex justify-content-sm-end mt-2 mt-sm-0">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.availability_templates.index') }}">Availability
                            Templates</a></li>
                    <li class="breadcrumb-item active">Generate Slots</li>
                </ol>
            </div>
        </div>


        <div class="card p-4 shadow-lg border-0 animate-card card-hover generate-card"
            style="border-radius:22px;background:linear-gradient(145deg,#ffffff,#eef2ff);">

            <div class="">

                <div class="text-center mb-4">
                    <h3 class="fw-bold text-primary mb-1">Generate Availability From Templates</h3>
                    <p class="text-muted">Choose a date range and auto-create availability slots</p>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger mb-3">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif



                <form method="POST" action="{{ route('admin.availability_templates.generate') }}">
                    @csrf

                    <!-- MODE SELECT -->
                    <div class="section-header">Select Generation Mode</div>
                    <select id="mode" name="mode" class="form-select mb-4">
                        <option value="current_week" {{ old('mode') === 'current_week' ? 'selected' : '' }}>Current Week
                        </option>
                        <option value="week_of_month" {{ old('mode') === 'week_of_month' ? 'selected' : '' }}>Week of Month
                        </option>
                        <option value="entire_month" {{ old('mode') === 'entire_month' ? 'selected' : '' }}>Entire Month
                        </option>
                        <option value="custom_range" {{ old('mode') === 'custom_range' ? 'selected' : '' }}>Custom Range
                        </option>
                    </select>


                    <!-- WEEK OF MONTH OPTIONS -->
                    <div id="week-options" style="display:none;">
                        <div class="section-header">Select Week of Month</div>

                        <div class="row g-3">

                            <div class="col-md-4">
                                <label class="input-label">Month</label>
                                <select name="month" class="form-select">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}" {{ old('month') == $m ? 'selected' : '' }}>
                                            {{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                                    @endfor
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="input-label">Year</label>
                                <select name="year" class="form-select">
                                    @php $year = date('Y'); @endphp
                                    @for ($i = 0; $i <= 5; $i++)
                                        <option value="{{ $year + $i }}"
                                            {{ old('year') == $year + $i ? 'selected' : '' }}>{{ $year + $i }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="input-label">Week Number</label>
                                <select name="week_number" class="form-select">
                                    <option value="1" {{ old('week_number') === '1' ? 'selected' : '' }}>1st Week
                                    </option>
                                    <option value="2" {{ old('week_number') === '2' ? 'selected' : '' }}>2nd Week
                                    </option>
                                    <option value="3" {{ old('week_number') === '3' ? 'selected' : '' }}>3rd Week
                                    </option>
                                    <option value="4" {{ old('week_number') === '4' ? 'selected' : '' }}>4th Week
                                    </option>
                                    <option value="5" {{ old('week_number') === '5' ? 'selected' : '' }}>5th Week
                                    </option>
                                </select>
                            </div>

                        </div>
                    </div>


                    <!-- ENTIRE MONTH -->
                    <div id="month-options" style="display:none;">
                        <div class="section-header">Select Month</div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="input-label">Month</label>
                                <select name="month" class="form-select">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}" {{ old('month') == $m ? 'selected' : '' }}>
                                            {{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                                    @endfor
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="input-label">Year</label>
                                <select name="year" class="form-select">
                                    @php $year = date('Y'); @endphp
                                    @for ($i = 0; $i <= 5; $i++)
                                        <option value="{{ $year + $i }}"
                                            {{ old('year') == $year + $i ? 'selected' : '' }}>{{ $year + $i }}
                                        </option>
                                    @endfor
                                </select>
                            </div>

                        </div>
                    </div>


                    <!-- CUSTOM RANGE -->
                    <div id="custom-range-options" style="display:none;">
                        <div class="section-header">Select Custom Range</div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="input-label">Start Date</label>
                                <input type="date" name="custom_start" value="{{ old('custom_start') }}"
                                    class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label class="input-label">End Date</label>
                                <input type="date" name="custom_end" value="{{ old('custom_end') }}"
                                    class="form-control">
                            </div>

                        </div>
                    </div>


                    <div class="text-end mt-4">
                        <button class="btn btn-success px-5 py-2 fw-bold">Generate</button>
                        <a href="{{ route('admin.availability_templates.index') }}"
                            class="btn btn-secondary px-4 py-2">Back</a>
                    </div>

                </form>

            </div>

        </div>

    </div>
@endsection


@section('custom_js_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modeSelect = document.getElementById('mode');
            const val = modeSelect.value;

            toggleSections(val);
        });

        document.getElementById('mode').addEventListener('change', function() {
            toggleSections(this.value);
        });

        function toggleSections(v) {
            document.getElementById('week-options').style.display = (v === 'week_of_month') ? 'block' : 'none';
            document.getElementById('month-options').style.display = (v === 'entire_month') ? 'block' : 'none';
            document.getElementById('custom-range-options').style.display = (v === 'custom_range') ? 'block' : 'none';
        }
    </script>
@endsection
