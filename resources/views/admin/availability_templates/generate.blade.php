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
                    <li class="breadcrumb-item active">Generate Availability Slots</li>

                </ol>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <form method="POST" action="{{ route('admin.availability.generate') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Select Mode</label>
                        <select id="mode" name="mode" class="form-control">
                            <option value="current_week">Current Week</option>
                            <option value="week_of_month">Week of Month</option>
                            <option value="entire_month">Entire Month</option>
                            <option value="custom_range">Custom Range</option>
                        </select>
                    </div>

                    <div id="week-options" style="display:none;">
                        <div class="row">
                            <div class="col-md-3">
                                <label>Month</label>
                                <select id="month" name="month" class="form-control">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}">
                                            {{ \Carbon\Carbon::create()->month($m)->format('F') }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Year</label>
                                <input type="number" name="year" class="form-control" value="{{ date('Y') }}" />
                            </div>
                            <div class="col-md-3">
                                <label>Week #</label>
                                <select name="week_number" class="form-control">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option value="3">3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="month-options" style="display:none;">
                        <div class="row">
                            <div class="col-md-3">
                                <label>Month</label>
                                <select name="month" class="form-control">
                                    @for ($m = 1; $m <= 12; $m++)
                                        <option value="{{ $m }}">
                                            {{ \Carbon\Carbon::create()->month($m)->format('F') }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Year</label>
                                <input type="number" name="year" class="form-control" value="{{ date('Y') }}" />
                            </div>
                        </div>
                    </div>

                    <div id="custom-range-options" style="display:none;">
                        <div class="row">
                            <div class="col-md-3">
                                <label>Start</label>
                                <input type="date" name="custom_start" class="form-control" />
                            </div>
                            <div class="col-md-3">
                                <label>End</label>
                                <input type="date" name="custom_end" class="form-control" />
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-success">Generate</button>
                        <a class="btn btn-secondary" href="{{ route('admin.availability_templates.index') }}">Back</a>
                    </div>
                </form>
            </div>
        </div>





        {{-- <h4>Generate Availability Slots</h4> --}}


    </div>
@endsection

@section('custom_js_scripts')
    <script>
        document.getElementById('mode').addEventListener('change', function() {
            const val = this.value;
            document.getElementById('week-options').style.display = (val === 'week_of_month') ? 'block' : 'none';
            document.getElementById('month-options').style.display = (val === 'entire_month') ? 'block' : 'none';
            document.getElementById('custom-range-options').style.display = (val === 'custom_range') ? 'block' :
                'none';
        });
    </script>
@endsection
