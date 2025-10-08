{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row page-titles mx-0 mb-3">
        <div class="col-sm-6 p-0">
            <div class="welcome-text">
                <h4>Configuration</h4>
            </div>
        </div>
        <div class="col-sm-6 p-0 justify-content-sm-end mt-2 mt-sm-0 d-flex">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('admin.configurations.admin_index') }}">Configuration</a></li>
            </ol>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Listing</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-responsive-lg mb-0">
                            <thead>
                                <tr>
                                    <th><strong>S.No</strong></th>
                                    <th><strong>Name</strong></th>
                                    <th><strong>Value</strong></th>
                                    <th><strong>Action</strong></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ $configuration->id }}</td>
                                    <td>{{ $configuration->name }}</td>
                                    <td>{{ $configuration->value }}</td>
                                    <td>
                                        <a href="{{ route('admin.configurations.admin_edit', $configuration->id) }}" class="btn btn-primary shadow btn-xs sharp mr-1"><i class="fas fa-pencil-alt"></i></a>
                                        <a href="{{ route('admin.configurations.admin_delete', $configuration->id) }}" class="btn btn-danger shadow btn-xs sharp"><i class="fa fa-trash"></i></a>
                                        <a href="{{ route('admin.configurations.admin_index') }}" class="btn btn-primary shadow btn-xs sharp"><i class="fa fa-list"></i></a>
                                        <a href="{{ route('admin.configurations.admin_add') }}" class="btn btn-primary shadow btn-xs sharp"><i class="fa fa-plus"></i></a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection