@if(Session::has('success'))
	<div class="container-fluid">
		<div class="alert alert-success alert-dismissible alert-alt fade show">
		    <strong>Success</strong> {{ Session::get('success') }}
		    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close">
		</div>
	</div>
@elseif(Session::has('info'))
	<div class="container-fluid">
		<div class="alert alert-info alert-dismissible alert-alt fade show">
		    <strong>Info</strong> {{ Session::get('info') }}
		    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close">
		</div>
	</div>
@elseif(Session::has('warning'))
	<div class="container-fluid">
		<div class="alert alert-warning alert-dismissible alert-alt fade show">
		    <strong>Warning</strong> {{ Session::get('warning') }}
		    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close">
		</div>
	</div>
@elseif(Session::has('error'))
	<div class="container-fluid">
		<div class="alert alert-danger alert-dismissible alert-alt fade show">
		    <strong>Error</strong> {{ Session::get('error') }}
		    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="btn-close">
		</div>
	</div>
@endif