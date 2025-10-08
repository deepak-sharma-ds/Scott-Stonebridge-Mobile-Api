{{-- Extends layout --}}
@extends('admin.layouts.app')

{{-- Content --}}
@section('content')

<div class="container-fluid">
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">{{ Str::ucfirst($prefix) }} Configuration</h4>
                </div>
                <div class="card-body">
                    <div class="basic-form">
                        <form action="{{ route('admin.configurations.admin_prefix', $prefix) }}" method="post" enctype="multipart/form-data">
                        @csrf
                        	@forelse($configurations as $configuration)
	                            <div class="form-group row">
	                            	@if($configuration->title)
	                            		@php
	                            			$keyTitle = $configuration->title;
	                            		@endphp
									@else
										@php
											$keyE = explode('.', $configuration->name);
											$keyTitle = ucfirst(str_replace('_', ' ', $keyE['1']));
										@endphp
									@endif
	                                <label class="col-sm-3 col-form-label">{{ $keyTitle }}</label>

	                                <div class="col-sm-6 form-group">
	                                	@php
	                                		$disabled = 'disabled';
	                                	@endphp
		                                @if($configuration->editable == 1)
			                                @php
			                                	$disabled = '';
			                                @endphp
		                                @endif
		                                	@php
												$keyE = explode('.', $configuration->name);
												$keyTitle = ucfirst(str_replace('_', ' ', $keyE['1']));
												
												$label = $configuration->title ? $configuration->title : $keyTitle;
												$i = $configuration->id;
											@endphp

											<input type="hidden" name="Configuration[{{ $i }}][input_type]" value="{{ $configuration->input_type ? $configuration->input_type : 'text' }}" {{ $disabled }}>
											<input type="hidden" name="Configuration[{{ $i }}][name]" value="{{ $configuration->name }}" {{ $disabled }}>

											{{-- ################# Field Building ############# --}}
											@php
												$inputType = $configuration->input_type ? $configuration->input_type : 'text';
											@endphp

											@if($inputType == 'multiple')
												$multiple = true;
												@isset($configuration->Params->multiple)
													$multiple = $configuration->Params->multiple;
												@endisset
											@elseif($inputType == 'select')
												@php
													$selected = explode(',', $configuration->value);
													$options = explode(',', $configuration->params);
													$newopts =array();
												@endphp
															
												<select name="Configuration[{{ $i }}][value]" id="Configuration{{ $i }}Value" class="form-control" {{ $disabled }}>
													@forelse($options as $key => $value)
														<option value="{{ $value }}" {{ $configuration->value == $value ? 'selected' : '' }}>{{ $value }}</option>
													@empty
													@endforelse
												</select>
											@elseif($inputType == 'checkbox')
												@if ($configuration->value == 1)
													<input type="{{ $inputType }}" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" checked="checked" data-bs-trigger="hover" class="form-check-input" data-bs-placement="right" data-title="{{ $configuration->description }}" value="1" {{ $disabled }}>
												@else
													<input type="{{ $inputType }}" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" data-bs-trigger="hover" class="form-check-input" data-bs-placement="right" data-title="{{ $configuration->description }}" value="1" {{ $disabled }}>
												@endif
											
											{{-- we will change it later, very soon addmore fields for checkboxs and select box --}}
											@elseif($inputType == 'multiple_checkbox')
												@php
													$valueArray = isset($configuration->value ) ? explode(',',$configuration->value) : array();
													$paramArray = !empty($configuration->params) ? explode('(NL)',$configuration->params) : array();
													//dd($paramArray);
												@endphp
												
												@if(count($paramArray) > 0)
													@foreach($paramArray as $key => $value)
														
														@php
															$checked = '';
															if(in_array($key,$valueArray)){
																$checked = 'checked="checked"';
															}
														@endphp
														<div class="form-check">
															<input type="checkbox" {{ $checked }} name="Configuration[{{ $i }}][value][{{$key}}]" id="Configuration.{{ $i }}.Value.[{{$key}}]"  data-bs-trigger="hover" class="form-check-input" data-bs-placement="right" data-title="{{ $configuration->description }}" value="1" {{ $disabled }}>
														
															<label class="form-check-label" for="Configuration.{{ $i }}.Value.[{{$key}}]">{{!empty($paramArray[$key]) ? $paramArray[$key] : ""}}</label>
														</div>
													@endforeach
												@endif

											@elseif($inputType == 'radio')
												@php
													$options = explode(',', $configuration->params);
												@endphp
												@forelse($options as $key => $value)
													@if (str_contains($value, ':'))
														@php
															$option = explode(':', $value);
														@endphp
														<div class="form-check">
															<input type="{{ $inputType }}" class="form-check-input" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" value="{{ $option[0] }}" {{ ($option[0] == $configuration->value) ? 'checked' : '' }} {{ $disabled }}>
															<label class="form-check-label" >{!! $option[1] !!}</label>
														</div>
													@else	
													<div class="form-check">
														<input type="{{ $inputType }}" class="form-check-input" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" value="{{ $value }}" {{ ($value == $configuration->value) ? 'checked="checked"' : '' }} {{ $disabled }}>
														<label class="form-check-label" >{!! $value !!}</label>
													</div>
													@endif
												@empty
												@endforelse	
											@elseif($inputType == 'textarea')
												<textarea name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" class="form-control h-100" rows="4" {{ $disabled }}>{{ $configuration->value }}</textarea>

											@elseif($inputType == 'file')

												<div class="img-parent-box">
													@if($configuration->value)
														<img src="{{ asset('storage/configuration-images/'.$configuration->value) }}" alt="{{ $configuration->value }}" id="img{{ $configuration->id }}" class="configurationPrefixImg img-for-onchange">
													@endif

			                                        <div class="form-file d-inline-block">
														<input type="{{ $inputType }}"  accept=".png, .jpg, .jpeg"  name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" class="form-file-input img-input-onchange ps-2 form-control" {{ $disabled }} {{ $configuration->params }}>
													</div>
												</div>

											@elseif($inputType == 'multiple_file')

												@if($configuration->value)

													@php
														$images = explode(",",$configuration->value);
													@endphp
													<div class="row m-0">
														@foreach ($images as $key => $image)
														<div class=" col-6 col-lg-4 col-xl-3 p-0 pe-2 mb-1" id="RemoveBannerImg_{{ $key }}-{{ $configuration->id }}">
															<div class="custom-image-delete" >
																<img src="{{ asset('storage/configuration-images/'.$image) }}" alt="{{ $image }}" class="rounded w-100 object-fit-cover" height="80px">
																
																<a href="javascript:void(0);" rdx-link="{{ route('admin.configurations.remove_config_image', ['id'=>$configuration->id,'name'=>$image]) }}" class="rdxUpdateAjax delete-btn text-danger" rdx-delete-box="RemoveBannerImg_{{ $key }}-{{ $configuration->id }}"><i class="far fa-times-circle"></i></a>
															</div>
														</div>
														@endforeach
													</div>

												@endif

												<div class="form-file d-block">
													<input type="file" accept=".png, .jpg, .jpeg" name="Configuration[{{ $i }}][value][]" id="Configuration.{{ $i }}.Value" class="form-file-input ps-2 form-control" {{ $disabled }} multiple>
												</div>

											@elseif($inputType == 'date')
												<input type="{{ $inputType }}" class=" form-control" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" class="form-control" value="{{ $configuration->value }}" {{ $disabled }}>

											@elseif($inputType == 'datetime')
												<input type="{{ $inputType }}" class=" form-control" id="min-date" inline="true" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" class="form-control" value="{{ $configuration->value }}" {{ $disabled }}>

											@else
												<input type="{{ $inputType }}" name="Configuration[{{ $i }}][value]" id="Configuration.{{ $i }}.Value" class="form-control" value="{{ $configuration->value }}" {{ $disabled }}>

											@endif

											@if ($configuration->description)
												<small class="d-block">{!! $configuration->description !!}</small>
											@endif
		                            </div>
	                            </div>
	                        @empty
	                        	<p class="text-center"> Records Not Found </p>
	                        @endforelse
                            <div class="form-group row">
                            	<div class="col-sm-3"></div>
                                <div class="col-sm-6">
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection