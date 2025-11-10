@csrf

<div class="mb-3">
    <label for="title" class="form-label">Title</label>
    <input type="text" name="title" id="title" value="{{ old('title', $package->title ?? '') }}"
        class="form-control" required>
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea name="description" id="description" class="form-control" rows="4">{{ old('description', $package->description ?? '') }}</textarea>
</div>

<div class="row">
    <div class="col-md-4 mb-3">
        <label for="price" class="form-label">Price</label>
        <input type="number" step="0.01" name="price" id="price"
            value="{{ old('price', $package->price ?? '') }}" class="form-control" required>
    </div>

    <div class="col-md-4 mb-3">
        <label for="currency" class="form-label">Currency</label>
        <input type="text" name="currency" id="currency" value="{{ old('currency', $package->currency ?? 'GBP') }}"
            class="form-control" maxlength="3">
    </div>
</div>

<div class="mb-3">
    <label for="shopify_tag" class="form-label">Shopify Tag</label>
    <input type="text" name="shopify_tag" id="shopify_tag"
        value="{{ old('shopify_tag', $package->shopify_tag ?? '') }}" class="form-control">
    <small class="text-muted">Tag used to link with Shopify customers</small>
</div>

<div class="mb-3">
    <label for="cover_image" class="form-label">Cover Image</label>
    <input type="file" name="cover_image" id="cover_image" class="form-control" accept="image/*">
    @if (!empty($package->cover_image))
        <div class="mt-2">
            <img src="{{ asset('storage/' . $package->cover_image) }}" alt="Cover Image" width="120"
                class="img-thumbnail">
        </div>
    @endif
</div>

<div class="mt-4">
    <button type="submit" class="btn btn-success">Save Package</button>
    <a href="{{ route('packages.index') }}" class="btn btn-secondary">Cancel</a>
</div>
