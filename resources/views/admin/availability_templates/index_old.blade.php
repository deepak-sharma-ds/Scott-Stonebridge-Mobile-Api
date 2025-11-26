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
                    <li class="breadcrumb-item active">Availability Templates List</li>
                </ol>
            </div>
        </div>


        <div class="row">
            <div class="col-xl-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4></h4>
                    <div>
                        <a href="{{ route('admin.availability_templates.create') }}" class="btn btn-primary">Edit
                            Templates</a>
                        <a href="{{ route('admin.availability_templates.generate.form') }}" class="btn btn-success">Generate Slots</a>
                    </div>
                </div>

                {{-- @if (session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif --}}

                <div class="card p-3">
                    @php
                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    @endphp

                    @foreach ($days as $day)
                        <h5 class="mt-3">{{ $day }}</h5>
                        <div>
                            @if (!empty($templates[$day]) && count($templates[$day]) > 0)
                                <ul>
                                    @foreach ($templates[$day] as $tpl)
                                        <li>{{ \Illuminate\Support\Str::limit($tpl->start_time, 5) }} -
                                            {{ \Illuminate\Support\Str::limit($tpl->end_time, 5) }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-muted">No slots</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
@endsection
