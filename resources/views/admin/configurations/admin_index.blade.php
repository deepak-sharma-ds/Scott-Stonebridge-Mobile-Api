{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row mb-5">
        <!-- Column starts -->
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Search</h4>
                </div>
                <div class="card-body" >
                    <form action="{{ route('admin.configurations.admin_index') }}" method="get">
                    @csrf
                        <input type="hidden" name="todo" value="Filter">
                        <div class="row">
                            <div class="mb-3 col-md-3">
                                <input type="search" name="title" class="form-control" placeholder="Title" value="{{ old('title', request()->input('title')) }}">
                            </div>
                            <div class="mb-3 col-md-3">
                                <input type="search" name="name" class="form-control" placeholder="Name" value="{{ old('name', request()->input('name')) }}">
                            </div>
                            <div class="mb-3 col-md-6 text-end">
                                <input type="submit" name="search" value="Search" class="btn btn-primary me-2"> 
                                <a href="{{ route('admin.configurations.admin_index') }}" class="btn btn-danger">Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Column starts -->
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Configuration</h4>
                    <a href="{{ route('admin.configurations.admin_add') }}" class="btn btn-primary">Add Configuration</a>
                </div>
                <div class="pe-4 ps-4 pt-2 pb-2">
                    <div class="table-responsive">
                        <table class="table table-responsive-lg mb-0">
                            <thead>
                                <tr>
                                    <th> <strong> Name </strong> </th>
                                    <th> <strong> Value </strong> </th>
                                    <th class="text-center" width="150px"> <strong> Actions </strong> </th>
                                </tr>
                            </thead>
                            <tbody>
                                
                                @forelse ($configurations as $configuration)
                                    <tr>
                                        <td> {{ $configuration->name }} </td>
                                        <td> {!! $configuration->value !!} </td>
                                        <td width="150px">
                                            <a href="{{ route('admin.configurations.admin_moveup', $configuration->id) }}" class="btn btn-primary shadow btn-xs sharp mr-1"><i class="fa fa-chevron-up" aria-hidden="true"></i></a>
                                            <a href="{{ route('admin.configurations.admin_movedown', $configuration->id) }}" class="btn btn-primary shadow btn-xs sharp mr-1"><i class="fa fa-chevron-down" aria-hidden="true"></i></a>
                                            <a href="{{ route('admin.configurations.admin_edit', $configuration->id) }}" class="btn btn-primary shadow btn-xs sharp mr-1"><i class="fas fa-pencil-alt"></i></a>
                                            <a href="{{ route('admin.configurations.admin_delete', $configuration->id) }}" class="btn btn-danger shadow btn-xs sharp"><i class="fa fa-trash"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center">
                                            <p>Records Not Found</p>
                                        </td>
                                    </tr>
                                @endforelse

                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    {{ $configurations->links() }}
                </div>
            </div>
        </div>
    </div>

</div>

@endsection