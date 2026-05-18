<div class="d-flex justify-content-center gap-2">
    <a href="{{ route('admin.products.edit', $product->MaSP) }}" class="btn btn-sm btn-warning bg-warning bg-opacity-10 text-warning border-0" title="Chỉnh sửa sản phẩm">
        <i class="bi bi-pencil-square"></i>
    </a>
    <button type="button" class="btn btn-sm btn-danger bg-danger bg-opacity-10 text-danger border-0" onclick="deleteProduct({{ $product->MaSP }})" title="Xóa sản phẩm">
        <i class="bi bi-trash"></i>
    </button>
</div>
