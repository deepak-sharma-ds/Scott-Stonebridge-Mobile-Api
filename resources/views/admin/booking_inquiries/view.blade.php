@extends('admin.layouts.app')

@role('Admin')
@section('content')
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4 class="fw-bold">Booking Inquiries</h4>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.scheduled-meetings') }}">Inquiries</a></li>
                <li class="breadcrumb-item active">View</li>
            </ol>
        </div>
    </div>
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">View Booking Details</h4>
                </div>
                <div class="card-body">
                    <div class="row justify-content-center">
                        <div class="col-lg-8 col-md-10">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-primary text-white">
                                    <h4 class="mb-0">Booking Details</h4>
                                </div>
                                <div class="card-body">
                                    <div class="mb-4 row align-items-center">
                                        <label class="col-sm-4 col-form-label fw-semibold">Name:</label>
                                        <div class="col-sm-8">
                                            <p class="form-control-plaintext mb-0">{{ $booking->name }}</p>
                                        </div>
                                    </div>

                                    <div class="mb-4 row align-items-center">
                                        <label class="col-sm-4 col-form-label fw-semibold">Email:</label>
                                        <div class="col-sm-8">
                                            <p class="form-control-plaintext mb-0">{{ $booking->email }}</p>
                                        </div>
                                    </div>

                                    <div class="mb-4 row align-items-center">
                                        <label class="col-sm-4 col-form-label fw-semibold">Phone Number:</label>
                                        <div class="col-sm-8">
                                            <p class="form-control-plaintext mb-0">{{ $booking->phone }}</p>
                                        </div>
                                    </div>

                                    <div class="mb-4 row align-items-center">
                                        <label class="col-sm-4 col-form-label fw-semibold">Date & Time:</label>
                                        <div class="col-sm-8">
                                            <p class="form-control-plaintext mb-0">{{ $booking->datetime->format(config('Reading.date_time_format')) }}</p>
                                        </div>
                                    </div>

                                    <div class="mb-4 row align-items-center">
                                        <label class="col-sm-4 col-form-label fw-semibold">Meet Link:</label>
                                        <div class="col-sm-8">
                                            <a href="{{ $booking->meeting_link }}" target="_blank" class="text-decoration-none">
                                                {{ $booking->meeting_link }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@endrole
